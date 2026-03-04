<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/printer.php';

// Set timezone to Indonesia (Asia/Jakarta)
date_default_timezone_set('Asia/Jakarta');

$action = $_GET['action'] ?? '';
$sumatraPdfPath = getSumatraPdfPath();
$uploadsDir = __DIR__ . "/uploads/";
$logsDir = __DIR__ . "/logs/";

// Create logs directory if not exists
if (!file_exists($logsDir)) {
    mkdir($logsDir, 0777, true);
}
if (!file_exists($uploadsDir)) {
    mkdir($uploadsDir, 0777, true);
}

function addLog($message, $type = "info") {
    global $logsDir;
    $logFile = $logsDir . "printer_" . date('Y-m-d') . ".log";
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] [$type] $message\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

function respondJson($payload) {
    echo json_encode($payload);
    exit;
}

function respondInlineText($message, $statusCode = 400) {
    http_response_code((int)$statusCode);
    header('Content-Type: text/plain; charset=UTF-8');
    echo (string)$message;
    exit;
}

function isUserAuthenticated() {
    return isset($_SESSION['auth_user']) && is_array($_SESSION['auth_user']) && !empty($_SESSION['auth_user']['nim_nipy']);
}

function requireUserAuthentication() {
    if (!isUserAuthenticated()) {
        respondJson([
            'success' => false,
            'auth_required' => true,
            'message' => 'Silakan login terlebih dahulu'
        ]);
    }
}

function isAdminAuthenticated() {
    return isUserAuthenticated() && (($_SESSION['auth_user']['role'] ?? '') === 'admin');
}

function requireAdminAuthentication() {
    requireUserAuthentication();
    if (!isAdminAuthenticated()) {
        respondJson([
            'success' => false,
            'forbidden' => true,
            'message' => 'Akses hanya untuk admin'
        ]);
    }
}

function getLoggedInUserName() {
    return $_SESSION['auth_user']['full_name'] ?? ($_SESSION['client_name'] ?? 'Unknown');
}

function getLoggedInUserPayload() {
    if (!isUserAuthenticated()) {
        return null;
    }

    return [
        'id' => (int)($_SESSION['auth_user']['id'] ?? 0),
        'nim_nipy' => (string)($_SESSION['auth_user']['nim_nipy'] ?? ''),
        'full_name' => (string)($_SESSION['auth_user']['full_name'] ?? ''),
        'role' => (string)($_SESSION['auth_user']['role'] ?? ''),
        'is_admin' => (($_SESSION['auth_user']['role'] ?? '') === 'admin'),
    ];
}

function getDatabaseOrFail() {
    try {
        return getDatabaseConnection();
    } catch (Throwable $e) {
        addLog("Database error: " . $e->getMessage(), 'error');
        respondJson([
            'success' => false,
            'message' => 'Koneksi database gagal. Periksa konfigurasi MySQL.'
        ]);
    }
}

function cleanupExpiredDoneJobs($db) {
    global $uploadsDir;
    static $alreadyRun = false;
    if ($alreadyRun) {
        return;
    }
    $alreadyRun = true;

    $throttleFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'printserver_cleanup_ts.txt';
    $nowTs = time();
    if (is_file($throttleFile)) {
        $lastRun = (int)@file_get_contents($throttleFile);
        if ($lastRun > 0 && ($nowTs - $lastRun) < 3) {
            return;
        }
    }
    @file_put_contents($throttleFile, (string)$nowTs);

    $autoDeleteSeconds = 60;
    $cutoff = date('Y-m-d H:i:s', time() - $autoDeleteSeconds);

    $stmt = $db->prepare(
        "SELECT id, stored_filename FROM print_jobs
         WHERE status = 'done' AND printed_at IS NOT NULL AND printed_at <= ? AND deleted_at IS NULL"
    );
    if (!$stmt) {
        return;
    }

    $stmt->bind_param('s', $cutoff);
    $stmt->execute();
    $result = $stmt->get_result();
    $expiredJobs = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $expiredJobs[] = $row;
        }
        $result->free();
    }
    $stmt->close();

    if (!$expiredJobs) {
        return;
    }

    $updateStmt = $db->prepare("UPDATE print_jobs SET status = 'deleted', deleted_at = NOW() WHERE id = ?");
    if (!$updateStmt) {
        return;
    }

    foreach ($expiredJobs as $job) {
        $filePath = $uploadsDir . $job['stored_filename'];
        if (file_exists($filePath)) {
            @unlink($filePath);
        }
        $jobId = (int)$job['id'];
        $updateStmt->bind_param('i', $jobId);
        $updateStmt->execute();
    }

    $updateStmt->close();
}

function getGlobalQueueStats($db) {
    $result = $db->query(
        "SELECT
            COUNT(*) AS total_active,
            SUM(CASE WHEN status = 'printing' THEN 1 ELSE 0 END) AS printing_count
         FROM print_jobs
         WHERE status IN ('ready', 'printing') AND deleted_at IS NULL"
    );

    $totalActive = 0;
    $printingCount = 0;
    if ($result) {
        $row = $result->fetch_assoc();
        $totalActive = (int)($row['total_active'] ?? 0);
        $printingCount = (int)($row['printing_count'] ?? 0);
        $result->free();
    }

    if ($printingCount > 0) {
        $status = 'Printing...';
    } elseif ($totalActive > 0) {
        $status = 'Queueing...';
    } else {
        $status = 'Ready';
    }
    return [
        'queue_count' => $totalActive,
        'status' => $status,
    ];
}

function buildQueuePositionMap($db) {
    $map = [];
    $result = $db->query(
        "SELECT id
         FROM print_jobs
         WHERE status IN ('ready', 'printing') AND deleted_at IS NULL
         ORDER BY
            CASE WHEN status = 'printing' THEN 0 ELSE 1 END,
            CASE WHEN status = 'ready' THEN updated_at ELSE created_at END ASC,
            id ASC"
    );

    if (!$result) {
        return $map;
    }

    $position = 1;
    while ($row = $result->fetch_assoc()) {
        $map[(int)$row['id']] = $position;
        $position++;
    }
    $result->free();

    return $map;
}

function getQueuePositionByJobId($db, $jobId) {
    $jobId = (int)$jobId;
    if ($jobId <= 0) {
        return null;
    }

    $queueMap = buildQueuePositionMap($db);
    return isset($queueMap[$jobId]) ? (int)$queueMap[$jobId] : null;
}

function normalizePrintMode($mode, $fallback = 'color') {
    $fallback = strtolower(trim((string)$fallback));
    if ($fallback !== 'grayscale') {
        $fallback = 'color';
    }

    $mode = strtolower(trim((string)$mode));
    if ($mode === 'bw' || $mode === 'blackwhite' || $mode === 'black_and_white' || $mode === 'monochrome') {
        $mode = 'grayscale';
    }
    if ($mode !== 'color' && $mode !== 'grayscale') {
        $mode = $fallback;
    }
    return $mode;
}

function getUploadedFiles($db, $ownerUserId) {
    $ownerUserId = (int)$ownerUserId;
    if ($ownerUserId <= 0) {
        return [];
    }

    $queuePositionMap = buildQueuePositionMap($db);

    $stmt = $db->prepare(
        "SELECT id, stored_filename, original_filename, file_size, status, print_mode, created_at, printed_at, last_error
         FROM print_jobs
         WHERE owner_user_id = ? AND deleted_at IS NULL AND status IN ('uploaded', 'ready', 'printing', 'done', 'cancelled', 'failed')
         ORDER BY created_at DESC, id DESC"
    );
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('i', $ownerUserId);
    $stmt->execute();
    $result = $stmt->get_result();

    $files = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $status = (string)$row['status'];
            $jobId = (int)$row['id'];
            $queuePosition = isset($queuePositionMap[$jobId]) ? (int)$queuePositionMap[$jobId] : null;
            $remaining = null;

            if ($status === 'done' && !empty($row['printed_at'])) {
                $printedTime = strtotime($row['printed_at']);
                if ($printedTime !== false) {
                    $remaining = max(0, 60 - (time() - $printedTime));
                }
            }

            $files[] = [
                'id' => (int)$row['id'],
                'name' => (string)$row['stored_filename'],
                'originalName' => (string)$row['original_filename'],
                'size' => (int)$row['file_size'],
                'uploadTime' => strtotime($row['created_at']) ?: time(),
                'status' => $status,
                'print_mode' => normalizePrintMode($row['print_mode'] ?? 'color'),
                'queue_position' => $queuePosition,
                'countdown' => $remaining,
                'error_message' => trim((string)($row['last_error'] ?? ''))
            ];
        }
        $result->free();
    }
    $stmt->close();

    return $files;
}

function getOwnedJobByFilename($db, $ownerUserId, $storedFilename) {
    $stmt = $db->prepare(
        "SELECT * FROM print_jobs WHERE owner_user_id = ? AND stored_filename = ? AND deleted_at IS NULL LIMIT 1"
    );
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('is', $ownerUserId, $storedFilename);
    $stmt->execute();
    $result = $stmt->get_result();
    $job = $result ? $result->fetch_assoc() : null;
    if ($result) {
        $result->free();
    }
    $stmt->close();
    return $job ?: null;
}

function updateJobStatus($db, $jobId, $status, $withPrintedAt = false, $withDeletedAt = false) {
    $status = (string)$status;
    $jobId = (int)$jobId;

    if ($withPrintedAt && $withDeletedAt) {
        $stmt = $db->prepare(
            "UPDATE print_jobs SET status = ?, printed_at = NOW(), deleted_at = NOW(), updated_at = NOW() WHERE id = ?"
        );
    } elseif ($withPrintedAt) {
        $stmt = $db->prepare(
            "UPDATE print_jobs SET status = ?, printed_at = NOW(), deleted_at = NULL, updated_at = NOW() WHERE id = ?"
        );
    } elseif ($withDeletedAt) {
        $stmt = $db->prepare(
            "UPDATE print_jobs SET status = ?, deleted_at = NOW(), updated_at = NOW() WHERE id = ?"
        );
    } else {
        $stmt = $db->prepare(
            "UPDATE print_jobs SET status = ?, updated_at = NOW() WHERE id = ?"
        );
    }

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('si', $status, $jobId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function runSumatraPrintCommand($sumatraPath, $pdfPath, $printerName = null) {
    $sumatraPath = (string)$sumatraPath;
    $pdfPath = (string)$pdfPath;
    $printerName = $printerName !== null ? (string)$printerName : null;

    $tempDir = rtrim(sys_get_temp_dir(), "\\/") . DIRECTORY_SEPARATOR;
    $scriptFile = $tempDir . 'print_' . uniqid('', true) . '.ps1';

    $escapedSumatra = str_replace("'", "''", $sumatraPath);
    $escapedPdf = str_replace("'", "''", $pdfPath);

    if ($printerName !== null && trim($printerName) !== '') {
        $escapedPrinter = str_replace("'", "''", $printerName);
        $printCommand = "& '" . $escapedSumatra . "' -print-to '" . $escapedPrinter . "' '" . $escapedPdf . "' -silent";
    } else {
        $printCommand = "& '" . $escapedSumatra . "' -print-to-default '" . $escapedPdf . "' -silent";
    }

    // Forward Sumatra exit code to the script process.
    $scriptContent = $printCommand . "\nexit \$LASTEXITCODE\n";
    if (@file_put_contents($scriptFile, $scriptContent) === false) {
        return [
            'ok' => false,
            'exit_code' => -1,
            'output' => 'Gagal menyiapkan script print'
        ];
    }

    $command = 'powershell -ExecutionPolicy Bypass -NoProfile -File "' . $scriptFile . '" 2>&1';
    $outputLines = [];
    $exitCode = 0;
    exec($command, $outputLines, $exitCode);
    @unlink($scriptFile);

    return [
        'ok' => ($exitCode === 0),
        'exit_code' => (int)$exitCode,
        'output' => trim(implode("\n", $outputLines))
    ];
}

function resolvePhpCliBinary() {
    $candidates = [];

    $configured = trim((string)envValue('PHP_CLI_PATH', ''));
    if ($configured !== '') {
        $candidates[] = $configured;
    }

    // Prefer XAMPP PHP CLI for this project.
    $candidates[] = 'C:\\xampp\\php\\php.exe';

    if (defined('PHP_BINDIR')) {
        $candidates[] = rtrim((string)PHP_BINDIR, "\\/") . DIRECTORY_SEPARATOR . 'php.exe';
    }

    $whereOutput = trim((string)@shell_exec('where php 2>nul'));
    if ($whereOutput !== '') {
        $lines = preg_split('/\r\n|\r|\n/', $whereOutput);
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line !== '') {
                $candidates[] = $line;
            }
        }
    }

    $checked = [];
    foreach ($candidates as $candidate) {
        $candidate = trim((string)$candidate);
        if ($candidate === '') {
            continue;
        }
        $key = strtolower($candidate);
        if (isset($checked[$key])) {
            continue;
        }
        $checked[$key] = true;
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return '';
}

function triggerQueueWorker($maxJobs = 50) {
    $workerScript = __DIR__ . DIRECTORY_SEPARATOR . 'queue_worker.php';
    if (!is_file($workerScript)) {
        return false;
    }

    $phpCli = resolvePhpCliBinary();
    if ($phpCli !== '') {
        $phpCliEscaped = str_replace('"', '', $phpCli);
        $workerEscaped = str_replace('"', '', $workerScript);
        $maxJobs = max(1, (int)$maxJobs);

        if (stripos(PHP_OS, 'WIN') === 0) {
            $command = 'cmd /c start "" /B "' . $phpCliEscaped . '" "' . $workerEscaped . '" ' . $maxJobs;
            if (function_exists('popen')) {
                @pclose(@popen($command, 'r'));
                return true;
            }

            $exitCode = 1;
            @exec($command, $unusedOutput, $exitCode);
            if ($exitCode === 0) {
                return true;
            }
        } else {
            $command = '"' . $phpCliEscaped . '" "' . $workerEscaped . '" ' . $maxJobs . ' > /dev/null 2>&1 &';
            @exec($command, $unusedOutput, $exitCode);
            if ((int)$exitCode === 0) {
                return true;
            }
        }
    }

    // Fallback inline only if background spawn fails.
    require_once $workerScript;
    if (!function_exists('runPrintQueueWorker')) {
        return false;
    }
    $result = runPrintQueueWorker(1);
    if (!is_array($result)) {
        return false;
    }
    if (($result['reason'] ?? '') === 'already_running') {
        return true;
    }
    return (bool)($result['ran'] ?? false);
}

if ($action == 'login') {
    $nimNipy = trim($_POST['nim_nipy'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if ($nimNipy === '' || $password === '') {
        respondJson([
            'success' => false,
            'message' => 'NIM/NIPY dan password wajib diisi'
        ]);
    }

    $db = getDatabaseOrFail();

    $stmt = $db->prepare("SELECT id, nim_nipy, full_name, password_hash, role, is_active FROM users WHERE nim_nipy = ? LIMIT 1");
    if (!$stmt) {
        addLog("Login gagal (prepare error): " . $db->error, 'error');
        respondJson([
            'success' => false,
            'message' => 'Terjadi kesalahan pada sistem login'
        ]);
    }

    $stmt->bind_param('s', $nimNipy);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        addLog("Login gagal: identitas $nimNipy tidak valid (Session: " . session_id() . ")", 'warning');
        respondJson([
            'success' => false,
            'message' => 'NIM/NIPY atau password salah'
        ]);
    }

    if ((int)$user['is_active'] !== 1) {
        respondJson([
            'success' => false,
            'message' => 'Akun Anda tidak aktif'
        ]);
    }

    session_regenerate_id(true);
    $_SESSION['auth_user'] = [
        'id' => (int)$user['id'],
        'nim_nipy' => $user['nim_nipy'],
        'full_name' => $user['full_name'],
        'role' => $user['role'] ?? '',
    ];
    $_SESSION['client_name'] = $user['full_name'];

    addLog("Login berhasil: {$user['full_name']} ({$user['nim_nipy']}) (Session: " . session_id() . ")", 'info');

    respondJson([
        'success' => true,
        'message' => 'Login berhasil',
        'user' => getLoggedInUserPayload()
    ]);
}
elseif ($action == 'auth_status') {
    respondJson([
        'success' => true,
        'logged_in' => isUserAuthenticated(),
        'user' => getLoggedInUserPayload()
    ]);
}
elseif ($action == 'logout') {
    $logoutUser = getLoggedInUserName();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }
    session_destroy();
    session_start();

    addLog("Logout: $logoutUser", 'info');
    respondJson(['success' => true, 'message' => 'Logout berhasil']);
}
elseif ($action == 'upload_file') {
    requireUserAuthentication();
    $db = getDatabaseOrFail();
    cleanupExpiredDoneJobs($db);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['file'])) {
        respondJson(['success' => false, 'message' => 'File upload tidak ditemukan']);
    }

    $file = $_FILES['file'];
    $filename = basename((string)($file['name'] ?? ''));
    $filesize = (int)($file['size'] ?? 0);
    $tmpFile = (string)($file['tmp_name'] ?? '');
    $maxSize = 100 * 1024 * 1024;

    $renamedFilename = trim((string)($_POST['renamed_filename'] ?? ''));
    $hideFilename = isset($_POST['hide_filename']) && $_POST['hide_filename'] === '1';

    if ($file['error'] !== UPLOAD_ERR_OK) {
        respondJson(['success' => false, 'message' => 'Kesalahan upload file: ' . $file['error']]);
    }
    if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'pdf') {
        respondJson(['success' => false, 'message' => 'Hanya file PDF yang diperbolehkan']);
    }
    if ($filesize > $maxSize) {
        respondJson(['success' => false, 'message' => 'Ukuran file melebihi 100MB']);
    }

    if ($renamedFilename !== '') {
        $safeRenamed = preg_replace('/[^A-Za-z0-9 _().-]/', '', $renamedFilename);
        $safeRenamed = trim((string)$safeRenamed);
        if ($safeRenamed === '') {
            $safeRenamed = pathinfo($filename, PATHINFO_FILENAME);
        }
        if (strtolower(pathinfo($safeRenamed, PATHINFO_EXTENSION)) !== 'pdf') {
            $safeRenamed .= '.pdf';
        }
        $finalFilename = basename($safeRenamed);
    } else {
        $finalFilename = $filename;
    }

    if ($finalFilename === '' || strtolower(pathinfo($finalFilename, PATHINFO_EXTENSION)) !== 'pdf') {
        $finalFilename = pathinfo($filename, PATHINFO_FILENAME) . '.pdf';
    }

    $destPath = $uploadsDir . $finalFilename;
    $counter = 1;
    $baseFilename = pathinfo($finalFilename, PATHINFO_FILENAME);
    $extension = pathinfo($finalFilename, PATHINFO_EXTENSION);

    while (file_exists($destPath)) {
        $finalFilename = $baseFilename . ' (' . $counter . ').' . $extension;
        $destPath = $uploadsDir . $finalFilename;
        $counter++;
    }

    if (!move_uploaded_file($tmpFile, $destPath)) {
        respondJson(['success' => false, 'message' => 'Gagal menyimpan file ke server']);
    }

    $ownerUserId = (int)($_SESSION['auth_user']['id'] ?? 0);
    $ownerNimNipy = (string)($_SESSION['auth_user']['nim_nipy'] ?? '');
    $ownerName = (string)($_SESSION['auth_user']['full_name'] ?? 'Unknown');

    if ($ownerUserId <= 0 || $ownerNimNipy === '') {
        @unlink($destPath);
        respondJson(['success' => false, 'message' => 'Session login tidak valid, silakan login ulang']);
    }

    $defaultMode = 'color';
    $stmt = $db->prepare(
        "INSERT INTO print_jobs
        (owner_user_id, owner_nim_nipy, owner_name, stored_filename, original_filename, file_size, hide_filename, print_mode, status, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'uploaded', NOW(), NOW())"
    );
    if (!$stmt) {
        @unlink($destPath);
        respondJson(['success' => false, 'message' => 'Gagal menyiapkan data antrian']);
    }

    $stmt->bind_param(
        'issssiis',
        $ownerUserId,
        $ownerNimNipy,
        $ownerName,
        $finalFilename,
        $finalFilename,
        $filesize,
        $hideFilename,
        $defaultMode
    );
    $ok = $stmt->execute();
    $stmtError = $stmt->error;
    $stmt->close();

    if (!$ok) {
        @unlink($destPath);
        respondJson(['success' => false, 'message' => 'Gagal menyimpan file ke antrian: ' . $stmtError]);
    }

    $displayName = $hideFilename ? '****.pdf' : $finalFilename;
    addLog("Queue add: {$displayName} | Client: {$ownerName} ({$ownerNimNipy})", 'info');

    respondJson([
        'success' => true,
        'message' => 'File berhasil di-upload. Klik Print untuk masuk antrian.',
        'job' => [
            'name' => $finalFilename,
            'originalName' => $finalFilename,
            'print_mode' => $defaultMode,
            'status' => 'uploaded'
        ]
    ]);
}
elseif ($action == 'list_users') {
    requireAdminAuthentication();
    $db = getDatabaseOrFail();

    $result = $db->query(
        "SELECT id, nim_nipy, full_name, role, is_active, created_at, updated_at FROM users ORDER BY created_at DESC"
    );

    if (!$result) {
        respondJson(['success' => false, 'message' => 'Gagal mengambil daftar user']);
    }

    $users = [];
    while ($row = $result->fetch_assoc()) {
        $users[] = [
            'id' => (int)$row['id'],
            'nim_nipy' => (string)$row['nim_nipy'],
            'full_name' => (string)$row['full_name'],
            'role' => (string)($row['role'] ?? ''),
            'is_active' => (int)$row['is_active'] === 1,
            'created_at' => (string)$row['created_at'],
            'updated_at' => (string)$row['updated_at'],
        ];
    }
    $result->free();

    respondJson(['success' => true, 'users' => $users]);
}
elseif ($action == 'create_user') {
    requireAdminAuthentication();
    $db = getDatabaseOrFail();

    $nimNipy = trim($_POST['nim_nipy'] ?? '');
    $fullName = trim($_POST['full_name'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $role = trim($_POST['role'] ?? 'mahasiswa');
    $isActive = (int)($_POST['is_active'] ?? 1) === 1 ? 1 : 0;

    if ($nimNipy === '' || $fullName === '' || $password === '') {
        respondJson(['success' => false, 'message' => 'NIM/NIPY, nama, dan password wajib diisi']);
    }

    if (!preg_match('/^[A-Za-z0-9._-]{4,30}$/', $nimNipy)) {
        respondJson(['success' => false, 'message' => 'Format NIM/NIPY tidak valid']);
    }

    if (strlen($password) < 6) {
        respondJson(['success' => false, 'message' => 'Password minimal 6 karakter']);
    }

    $allowedRoles = ['admin', 'dosen', 'mahasiswa', 'staff'];
    if (!in_array($role, $allowedRoles, true)) {
        $role = 'mahasiswa';
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare(
        "INSERT INTO users (nim_nipy, full_name, password_hash, role, is_active) VALUES (?, ?, ?, ?, ?)"
    );

    if (!$stmt) {
        respondJson(['success' => false, 'message' => 'Gagal menyiapkan data user baru']);
    }

    $stmt->bind_param('ssssi', $nimNipy, $fullName, $passwordHash, $role, $isActive);
    $ok = $stmt->execute();
    $errorNo = $stmt->errno;
    $errorMsg = $stmt->error;
    $stmt->close();

    if (!$ok) {
        if ((int)$errorNo === 1062) {
            respondJson(['success' => false, 'message' => 'NIM/NIPY sudah terdaftar']);
        }
        respondJson(['success' => false, 'message' => 'Gagal menambahkan user: ' . $errorMsg]);
    }

    addLog("User ditambahkan: $nimNipy ($fullName) oleh " . getLoggedInUserName(), 'info');
    respondJson(['success' => true, 'message' => 'User berhasil ditambahkan']);
}
elseif ($action == 'update_user') {
    requireAdminAuthentication();
    $db = getDatabaseOrFail();

    $id = (int)($_POST['id'] ?? 0);
    $nimNipy = trim($_POST['nim_nipy'] ?? '');
    $fullName = trim($_POST['full_name'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $role = trim($_POST['role'] ?? 'mahasiswa');
    $isActive = (int)($_POST['is_active'] ?? 1) === 1 ? 1 : 0;

    if ($id <= 0 || $nimNipy === '' || $fullName === '') {
        respondJson(['success' => false, 'message' => 'Data update user tidak lengkap']);
    }

    if (!preg_match('/^[A-Za-z0-9._-]{4,30}$/', $nimNipy)) {
        respondJson(['success' => false, 'message' => 'Format NIM/NIPY tidak valid']);
    }

    if ($password !== '' && strlen($password) < 6) {
        respondJson(['success' => false, 'message' => 'Password baru minimal 6 karakter']);
    }

    $allowedRoles = ['admin', 'dosen', 'mahasiswa', 'staff'];
    if (!in_array($role, $allowedRoles, true)) {
        $role = 'mahasiswa';
    }

    $currentUserId = (int)($_SESSION['auth_user']['id'] ?? 0);
    if ($id === $currentUserId && $isActive !== 1) {
        respondJson(['success' => false, 'message' => 'Anda tidak bisa menonaktifkan akun sendiri']);
    }

    $existingStmt = $db->prepare("SELECT id, role, is_active FROM users WHERE id = ? LIMIT 1");
    if (!$existingStmt) {
        respondJson(['success' => false, 'message' => 'Gagal memeriksa data user']);
    }
    $existingStmt->bind_param('i', $id);
    $existingStmt->execute();
    $existingResult = $existingStmt->get_result();
    $existingUser = $existingResult ? $existingResult->fetch_assoc() : null;
    $existingStmt->close();

    if (!$existingUser) {
        respondJson(['success' => false, 'message' => 'User tidak ditemukan']);
    }

    // Prevent removing the last active admin.
    $isExistingActiveAdmin = ($existingUser['role'] === 'admin' && (int)$existingUser['is_active'] === 1);
    $isTargetActiveAdmin = ($role === 'admin' && $isActive === 1);
    if ($isExistingActiveAdmin && !$isTargetActiveAdmin) {
        $adminCountResult = $db->query("SELECT COUNT(*) AS total FROM users WHERE role = 'admin' AND is_active = 1");
        $adminCount = 0;
        if ($adminCountResult) {
            $countRow = $adminCountResult->fetch_assoc();
            $adminCount = (int)($countRow['total'] ?? 0);
            $adminCountResult->free();
        }
        if ($adminCount <= 1) {
            respondJson(['success' => false, 'message' => 'Harus ada minimal 1 admin aktif']);
        }
    }

    if ($password !== '') {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $db->prepare(
            "UPDATE users SET nim_nipy = ?, full_name = ?, password_hash = ?, role = ?, is_active = ? WHERE id = ?"
        );
        if (!$stmt) {
            respondJson(['success' => false, 'message' => 'Gagal menyiapkan update user']);
        }
        $stmt->bind_param('ssssii', $nimNipy, $fullName, $passwordHash, $role, $isActive, $id);
    } else {
        $stmt = $db->prepare(
            "UPDATE users SET nim_nipy = ?, full_name = ?, role = ?, is_active = ? WHERE id = ?"
        );
        if (!$stmt) {
            respondJson(['success' => false, 'message' => 'Gagal menyiapkan update user']);
        }
        $stmt->bind_param('sssii', $nimNipy, $fullName, $role, $isActive, $id);
    }

    $ok = $stmt->execute();
    $errorNo = $stmt->errno;
    $errorMsg = $stmt->error;
    $stmt->close();

    if (!$ok) {
        if ((int)$errorNo === 1062) {
            respondJson(['success' => false, 'message' => 'NIM/NIPY sudah dipakai user lain']);
        }
        respondJson(['success' => false, 'message' => 'Gagal memperbarui user: ' . $errorMsg]);
    }

    if ($id === $currentUserId) {
        $_SESSION['auth_user']['nim_nipy'] = $nimNipy;
        $_SESSION['auth_user']['full_name'] = $fullName;
        $_SESSION['auth_user']['role'] = $role;
        $_SESSION['client_name'] = $fullName;
    }

    addLog("User diperbarui: $nimNipy ($fullName) oleh " . getLoggedInUserName(), 'info');
    respondJson(['success' => true, 'message' => 'User berhasil diperbarui']);
}
elseif ($action == 'delete_user') {
    requireAdminAuthentication();
    $db = getDatabaseOrFail();

    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        respondJson(['success' => false, 'message' => 'ID user tidak valid']);
    }

    $currentUserId = (int)($_SESSION['auth_user']['id'] ?? 0);
    if ($id === $currentUserId) {
        respondJson(['success' => false, 'message' => 'Akun sendiri tidak bisa dihapus']);
    }

    $selectStmt = $db->prepare("SELECT nim_nipy, full_name, role, is_active FROM users WHERE id = ? LIMIT 1");
    if (!$selectStmt) {
        respondJson(['success' => false, 'message' => 'Gagal memeriksa user']);
    }
    $selectStmt->bind_param('i', $id);
    $selectStmt->execute();
    $result = $selectStmt->get_result();
    $targetUser = $result ? $result->fetch_assoc() : null;
    $selectStmt->close();

    if (!$targetUser) {
        respondJson(['success' => false, 'message' => 'User tidak ditemukan']);
    }

    if ($targetUser['role'] === 'admin' && (int)$targetUser['is_active'] === 1) {
        $adminCountResult = $db->query("SELECT COUNT(*) AS total FROM users WHERE role = 'admin' AND is_active = 1");
        $adminCount = 0;
        if ($adminCountResult) {
            $countRow = $adminCountResult->fetch_assoc();
            $adminCount = (int)($countRow['total'] ?? 0);
            $adminCountResult->free();
        }
        if ($adminCount <= 1) {
            respondJson(['success' => false, 'message' => 'Tidak bisa menghapus admin aktif terakhir']);
        }
    }

    $deleteStmt = $db->prepare("DELETE FROM users WHERE id = ?");
    if (!$deleteStmt) {
        respondJson(['success' => false, 'message' => 'Gagal menyiapkan penghapusan user']);
    }
    $deleteStmt->bind_param('i', $id);
    $ok = $deleteStmt->execute();
    $errorMsg = $deleteStmt->error;
    $deleteStmt->close();

    if (!$ok) {
        respondJson(['success' => false, 'message' => 'Gagal menghapus user: ' . $errorMsg]);
    }

    addLog("User dihapus: {$targetUser['nim_nipy']} ({$targetUser['full_name']}) oleh " . getLoggedInUserName(), 'warning');
    respondJson(['success' => true, 'message' => 'User berhasil dihapus']);
}
elseif ($action == 'save_client_name') {
    respondJson([
        'success' => false,
        'message' => 'Endpoint ini sudah tidak dipakai. Gunakan login berbasis NIM/NIPY.'
    ]);
}
elseif ($action == 'get_files') {
    requireUserAuthentication();
    $db = getDatabaseOrFail();
    cleanupExpiredDoneJobs($db);

    $ownerUserId = (int)($_SESSION['auth_user']['id'] ?? 0);
    $files = getUploadedFiles($db, $ownerUserId);
    $stats = getGlobalQueueStats($db);

    respondJson([
        'success' => true,
        'files' => $files,
        'queue_count' => (int)$stats['queue_count'],
        'global_queue_count' => (int)$stats['queue_count'],
        'global_status' => (string)$stats['status']
    ]);
}
elseif ($action == 'preview_file') {
    requireUserAuthentication();
    $db = getDatabaseOrFail();
    cleanupExpiredDoneJobs($db);

    $jobId = trim((string)($_GET['job_id'] ?? ''));
    if ($jobId === '') {
        respondInlineText('Job ID tidak ditemukan', 400);
    }

    $ownerUserId = (int)($_SESSION['auth_user']['id'] ?? 0);
    $job = getOwnedJobByFilename($db, $ownerUserId, $jobId);
    if (!$job) {
        respondInlineText('Anda tidak memiliki akses untuk preview file ini', 403);
    }

    $jobFile = $uploadsDir . (string)$job['stored_filename'];
    if (!is_file($jobFile)) {
        respondInlineText('File tidak ditemukan pada storage', 404);
    }

    $downloadName = (string)($job['original_filename'] ?? $job['stored_filename'] ?? 'preview.pdf');
    $downloadName = preg_replace('/[^A-Za-z0-9._-]/', '_', $downloadName);
    $downloadName = is_string($downloadName) ? trim($downloadName) : '';
    if ($downloadName === '') {
        $downloadName = 'preview.pdf';
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $downloadName . '"');
    header('X-Content-Type-Options: nosniff');

    $fileSize = @filesize($jobFile);
    if (is_int($fileSize) && $fileSize > 0) {
        header('Content-Length: ' . $fileSize);
    }

    @readfile($jobFile);
    exit;
}
elseif ($action == 'delete_file') {
    requireUserAuthentication();
    $db = getDatabaseOrFail();
    cleanupExpiredDoneJobs($db);

    $jobId = $_POST['job_id'] ?? '';

    if (empty($jobId)) {
        respondJson(['success' => false, 'message' => 'Job ID tidak ditemukan']);
    }

    $ownerUserId = (int)($_SESSION['auth_user']['id'] ?? 0);
    $job = getOwnedJobByFilename($db, $ownerUserId, $jobId);
    if (!$job) {
        respondJson(['success' => false, 'message' => 'Anda tidak memiliki akses untuk menghapus file ini']);
    }

    $jobFile = $uploadsDir . $job['stored_filename'];
    if (file_exists($jobFile) && !@unlink($jobFile)) {
        respondJson(['success' => false, 'message' => 'Gagal menghapus file fisik']);
    }

    if (!updateJobStatus($db, (int)$job['id'], 'deleted', false, true)) {
        respondJson(['success' => false, 'message' => 'Gagal menghapus data antrian']);
    }

    if (isset($_SESSION['last_job']) && ($_SESSION['last_job']['job_id'] ?? '') === $jobId) {
        unset($_SESSION['last_job']);
    }

    addLog("Queue delete: {$jobId} | Client: " . getLoggedInUserName(), 'info');
    respondJson(['success' => true, 'message' => 'File berhasil dihapus']);
}
elseif ($action == 'reset_file_status') {
    requireUserAuthentication();
    $db = getDatabaseOrFail();
    cleanupExpiredDoneJobs($db);

    $jobId = $_POST['job_id'] ?? '';

    if (empty($jobId)) {
        respondJson(['success' => false, 'message' => 'Job ID tidak ditemukan']);
    }

    $ownerUserId = (int)($_SESSION['auth_user']['id'] ?? 0);
    $job = getOwnedJobByFilename($db, $ownerUserId, $jobId);
    if (!$job) {
        respondJson(['success' => false, 'message' => 'Anda tidak memiliki akses untuk mengulangi file ini']);
    }

    $requestedMode = normalizePrintMode($_POST['print_mode'] ?? '', $job['print_mode'] ?? 'color');

    $jobFile = $uploadsDir . $job['stored_filename'];
    if (!file_exists($jobFile)) {
        respondJson(['success' => false, 'message' => 'File tidak ditemukan. Silakan upload ulang.']);
    }

    $currentStatus = (string)$job['status'];
    if ($currentStatus === 'printing') {
        $queuePos = getQueuePositionByJobId($db, (int)$job['id']);
        respondJson([
            'success' => true,
            'status' => 'printing',
            'queue_position' => $queuePos,
            'print_mode' => normalizePrintMode($job['print_mode'] ?? 'color'),
            'message' => 'File sedang dicetak'
        ]);
    }

    if ($currentStatus === 'ready') {
        $readyStmt = $db->prepare(
            "UPDATE print_jobs
             SET print_mode = ?, updated_at = NOW()
             WHERE id = ?"
        );
        if ($readyStmt) {
            $jobPrimaryId = (int)$job['id'];
            $readyStmt->bind_param('si', $requestedMode, $jobPrimaryId);
            $readyStmt->execute();
            $readyStmt->close();
        }
        triggerQueueWorker();
        $queuePos = getQueuePositionByJobId($db, (int)$job['id']);
        respondJson([
            'success' => true,
            'status' => 'ready',
            'queue_position' => $queuePos,
            'print_mode' => $requestedMode,
            'message' => 'File sudah ada dalam antrian print'
        ]);
    }

    $stmt = $db->prepare(
        "UPDATE print_jobs
         SET status = 'ready', print_mode = ?, printed_at = NULL, deleted_at = NULL, last_error = NULL, updated_at = NOW()
         WHERE id = ?"
    );
    if (!$stmt) {
        respondJson(['success' => false, 'message' => 'Gagal menyiapkan ulang data antrian']);
    }
    $jobPrimaryId = (int)$job['id'];
    $stmt->bind_param('si', $requestedMode, $jobPrimaryId);
    $ok = $stmt->execute();
    $stmt->close();

    if (!$ok) {
        respondJson(['success' => false, 'message' => 'Gagal mengubah status file']);
    }

    $retryModeLabel = $requestedMode === 'grayscale' ? 'Grayscale' : 'Color';
    $displayFilename = ((int)($job['hide_filename'] ?? 0) === 1) ? '****.pdf' : (string)$job['original_filename'];
    $queuePosBeforeTrigger = getQueuePositionByJobId($db, (int)$job['id']);
    $queueSuffix = $queuePosBeforeTrigger ? " | Queue: #{$queuePosBeforeTrigger}" : '';
    addLog("Print sent: {$displayFilename} | Client: " . getLoggedInUserName() . " | Mode: {$retryModeLabel}", 'print');
    addLog("Queue enqueue: {$displayFilename} | Client: " . getLoggedInUserName() . " | Mode: {$retryModeLabel}{$queueSuffix}", 'print');

    triggerQueueWorker();
    $queuePos = getQueuePositionByJobId($db, (int)$job['id']);
    respondJson([
        'success' => true,
        'status' => 'ready',
        'queue_position' => $queuePos,
        'print_mode' => $requestedMode,
        'message' => 'File siap untuk dicetak ulang'
    ]);
}
elseif ($action == 'print_file') {
    requireUserAuthentication();
    $db = getDatabaseOrFail();
    cleanupExpiredDoneJobs($db);

    $jobId = $_POST['job_id'] ?? '';
    $clientName = getLoggedInUserName();

    if (empty($jobId)) {
        respondJson(['success' => false, 'message' => 'Job ID tidak ditemukan']);
    }

    $ownerUserId = (int)($_SESSION['auth_user']['id'] ?? 0);
    $job = getOwnedJobByFilename($db, $ownerUserId, $jobId);
    if (!$job) {
        addLog("Print denied: {$jobId} | Client: {$clientName}", 'print');
        respondJson(['success' => false, 'message' => 'Anda tidak memiliki akses untuk mencetak file ini']);
    }

    $requestedMode = normalizePrintMode($_POST['print_mode'] ?? '', $job['print_mode'] ?? 'color');

    if ($job['status'] === 'deleted') {
        addLog("Print failed: {$jobId} sudah dihapus | Client: {$clientName}", 'print');
        respondJson(['success' => false, 'message' => 'File sudah tidak tersedia']);
    }

    $jobFile = $uploadsDir . $job['stored_filename'];
    if (!file_exists($jobFile)) {
        updateJobStatus($db, (int)$job['id'], 'failed', false, false);
        addLog("Print failed: {$jobId} file tidak ditemukan | Client: {$clientName}", 'print');
        respondJson(['success' => false, 'message' => 'File tidak ditemukan pada storage']);
    }

    $currentStatus = (string)$job['status'];
    if ($currentStatus === 'printing') {
        $queuePos = getQueuePositionByJobId($db, (int)$job['id']);
        respondJson([
            'success' => true,
            'message' => 'File sedang dicetak' . ($queuePos ? ' pada antrian #' . $queuePos : ''),
            'status' => 'printing',
            'print_mode' => normalizePrintMode($job['print_mode'] ?? 'color'),
            'queue_position' => $queuePos
        ]);
    }

    if ($currentStatus === 'ready') {
        $readyStmt = $db->prepare(
            "UPDATE print_jobs
             SET print_mode = ?, updated_at = NOW()
             WHERE id = ?"
        );
        if ($readyStmt) {
            $jobPrimaryId = (int)$job['id'];
            $readyStmt->bind_param('si', $requestedMode, $jobPrimaryId);
            $readyStmt->execute();
            $readyStmt->close();
        }
        triggerQueueWorker();
        $queuePos = getQueuePositionByJobId($db, (int)$job['id']);
        respondJson([
            'success' => true,
            'message' => 'File sudah ada dalam antrian print' . ($queuePos ? ' (#' . $queuePos . ')' : ''),
            'status' => 'ready',
            'print_mode' => $requestedMode,
            'queue_position' => $queuePos
        ]);
    }

    $enqueueStmt = $db->prepare(
        "UPDATE print_jobs
         SET status = 'ready', print_mode = ?, printed_at = NULL, deleted_at = NULL, last_error = NULL, updated_at = NOW()
         WHERE id = ?"
    );
    if (!$enqueueStmt) {
        respondJson(['success' => false, 'message' => 'Gagal menambahkan file ke antrian print']);
    }
    $jobPrimaryId = (int)$job['id'];
    $enqueueStmt->bind_param('si', $requestedMode, $jobPrimaryId);
    $enqueueOk = $enqueueStmt->execute();
    $enqueueStmt->close();
    if (!$enqueueOk) {
        respondJson(['success' => false, 'message' => 'Gagal menyimpan status antrian']);
    }

    $_SESSION['last_job'] = [
        'original_name' => (string)$job['original_filename'],
        'filename' => $jobId,
        'job_id' => $jobId,
        'status' => 'ready',
        'start_time' => time()
    ];

    $displayFilename = ((int)$job['hide_filename'] === 1) ? '****.pdf' : (string)$job['original_filename'];
    $modeLabel = $requestedMode === 'grayscale' ? 'Grayscale' : 'Color';
    $queuePosBeforeTrigger = getQueuePositionByJobId($db, (int)$job['id']);
    $queueSuffix = $queuePosBeforeTrigger ? " | Queue: #{$queuePosBeforeTrigger}" : '';
    addLog("Print sent: {$displayFilename} | Client: {$clientName} | Mode: {$modeLabel}", 'print');
    addLog("Queue enqueue: {$displayFilename} | Client: {$clientName} | Mode: {$modeLabel}{$queueSuffix}", 'print');

    triggerQueueWorker();
    $queuePos = getQueuePositionByJobId($db, (int)$job['id']);

    respondJson([
        'success' => true,
        'message' => 'File masuk antrian print' . ($queuePos ? ' (#' . $queuePos . ')' : ''),
        'status' => 'ready',
        'print_mode' => $requestedMode,
        'queue_position' => $queuePos
    ]);
}
elseif ($action == 'check_status') {
    requireUserAuthentication();
    $db = getDatabaseOrFail();
    cleanupExpiredDoneJobs($db);

    $jobId = $_POST['job_id'] ?? '';

    if (empty($jobId)) {
        respondJson(['success' => false, 'message' => 'Job ID tidak ditemukan']);
    }

    $ownerUserId = (int)($_SESSION['auth_user']['id'] ?? 0);
    $job = getOwnedJobByFilename($db, $ownerUserId, $jobId);
    if (!$job) {
        respondJson(['success' => false, 'message' => 'Anda tidak memiliki akses untuk melihat status file ini']);
    }

    $jobFile = $uploadsDir . $job['stored_filename'];
    $autoDeleteSeconds = 60;

    if ($job['status'] === 'deleted') {
        respondJson([
            'success' => true,
            'completed' => true,
            'status' => 'done',
            'countdown' => 0,
            'message' => 'File sudah tidak ada di antrian'
        ]);
    }

    $status = (string)$job['status'];
    $lastError = trim((string)($job['last_error'] ?? ''));
    $printMode = normalizePrintMode($job['print_mode'] ?? 'color');
    if ($status === 'done') {
        $printedAt = !empty($job['printed_at']) ? strtotime($job['printed_at']) : false;
        $elapsed = $printedAt ? (time() - $printedAt) : 0;
        $remainingTime = max(0, $autoDeleteSeconds - $elapsed);

        if ($remainingTime <= 0) {
            if (file_exists($jobFile)) {
                @unlink($jobFile);
            }
            updateJobStatus($db, (int)$job['id'], 'deleted', false, true);
            respondJson([
                'success' => true,
                'completed' => true,
                'status' => 'done',
                'print_mode' => $printMode,
                'countdown' => 0,
                'message' => 'Print selesai!'
            ]);
        }

        respondJson([
            'success' => true,
            'completed' => true,
            'status' => 'done',
            'print_mode' => $printMode,
            'countdown' => $remainingTime,
            'message' => 'Print selesai!'
        ]);
    }

    if ($status === 'printing') {
        $queuePos = getQueuePositionByJobId($db, (int)$job['id']);
        respondJson([
            'success' => true,
            'completed' => false,
            'status' => 'printing',
            'print_mode' => $printMode,
            'queue_position' => $queuePos,
            'message' => $queuePos ? ('Mencetak... #' . $queuePos) : 'Mencetak...'
        ]);
    }

    if ($status === 'ready') {
        triggerQueueWorker();
        $queuePos = getQueuePositionByJobId($db, (int)$job['id']);
        respondJson([
            'success' => true,
            'completed' => false,
            'status' => 'ready',
            'print_mode' => $printMode,
            'queue_position' => $queuePos,
            'message' => $queuePos ? ('Menunggu giliran print #' . $queuePos) : 'Menunggu giliran print'
        ]);
    }

    if ($status === 'uploaded') {
        respondJson([
            'success' => true,
            'completed' => false,
            'status' => 'uploaded',
            'print_mode' => $printMode,
            'message' => 'File terupload. Klik Print untuk masuk antrian.'
        ]);
    }

    if ($status === 'cancelled' || $status === 'failed') {
        $message = $status === 'failed' ? 'Print error' : 'Print dibatalkan';
        if ($lastError !== '') {
            $message .= ': ' . $lastError;
        }
        respondJson([
            'success' => true,
            'completed' => false,
            'status' => $status,
            'print_mode' => $printMode,
            'error_message' => $lastError,
            'message' => $message
        ]);
    }

    respondJson([
        'success' => true,
        'completed' => false,
        'status' => $status,
        'print_mode' => $printMode,
        'error_message' => $lastError,
        'message' => 'Status file diperbarui'
    ]);
}
elseif ($action == 'cancel_print') {
    requireUserAuthentication();
    $db = getDatabaseOrFail();
    cleanupExpiredDoneJobs($db);

    $jobId = $_POST['job_id'] ?? '';

    if (empty($jobId)) {
        respondJson(['success' => false, 'message' => 'Job ID tidak ditemukan']);
    }

    $ownerUserId = (int)($_SESSION['auth_user']['id'] ?? 0);
    $job = getOwnedJobByFilename($db, $ownerUserId, $jobId);
    if (!$job) {
        respondJson(['success' => false, 'message' => 'Anda tidak memiliki akses untuk membatalkan print ini']);
    }

    $killCommand = 'powershell -NoProfile -Command "Get-Process SumatraPDF -ErrorAction SilentlyContinue | Stop-Process -Force -ErrorAction SilentlyContinue"';
    pclose(popen($killCommand, "r"));

    $stmt = $db->prepare(
        "UPDATE print_jobs
         SET status = 'cancelled', printed_at = NULL, last_error = 'Dibatalkan oleh user', updated_at = NOW()
         WHERE id = ?"
    );
    if (!$stmt) {
        respondJson(['success' => false, 'message' => 'Gagal mengubah status cancel']);
    }
    $jobPrimaryId = (int)$job['id'];
    $stmt->bind_param('i', $jobPrimaryId);
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) {
        respondJson(['success' => false, 'message' => 'Gagal menyimpan status cancel']);
    }

    if (isset($_SESSION['last_job']) && ($_SESSION['last_job']['job_id'] ?? '') === $jobId) {
        unset($_SESSION['last_job']);
    }

    $displayFilename = ((int)$job['hide_filename'] === 1) ? '****.pdf' : $job['original_filename'];
    addLog("Print cancelled: {$displayFilename} | Client: " . getLoggedInUserName(), 'print');
    respondJson(['success' => true, 'message' => 'Pencetakan dibatalkan']);
}
elseif ($action == 'get_logs') {
    requireUserAuthentication();
    $logFile = $logsDir . "printer_" . date('Y-m-d') . ".log";
    $logs = [];

    if (file_exists($logFile)) {
        $lines = file($logFile);
        $lines = array_map('trim', $lines ?: []);
        $printLines = array_values(array_filter($lines, function($line) {
            $line = (string)$line;
            if ($line === '') {
                return false;
            }
            return stripos($line, '[print]') !== false;
        }));

        // Return latest 100 print-related logs globally
        $logs = array_slice($printLines, -100);
    }

    respondJson(['success' => true, 'logs' => $logs]);
}
elseif ($action == 'debug') {
    requireUserAuthentication();
    $db = getDatabaseOrFail();
    cleanupExpiredDoneJobs($db);
    $ownerUserId = (int)($_SESSION['auth_user']['id'] ?? 0);
    $files = getUploadedFiles($db, $ownerUserId);
    $stats = getGlobalQueueStats($db);
    $uploadsExists = file_exists($uploadsDir);
    $uploadsReadable = is_readable($uploadsDir);
    $uploadsList = [];
    
    if ($uploadsExists && is_dir($uploadsDir)) {
        $uploadsList = array_diff(scandir($uploadsDir), ['.', '..']);
    }
    
    echo json_encode([
        'success' => true,
        'debug' => [
            'uploadsDir' => $uploadsDir,
            'uploadsExists' => $uploadsExists,
            'uploadsReadable' => $uploadsReadable,
            'uploadsList' => $uploadsList,
            'userFiles' => $files,
            'globalQueue' => $stats,
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ]);
    exit;
}
else {
    echo json_encode(['success' => false, 'message' => 'Action tidak dikenali']);
    exit;
}
?>
