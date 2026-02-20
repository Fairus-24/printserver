<?php
session_start();
header('Content-Type: application/json');

// Set timezone to Indonesia (Asia/Jakarta)
date_default_timezone_set('Asia/Jakarta');

$action = $_GET['action'] ?? '';
$printer = "EPSONL121";
$uploadsDir = __DIR__ . "/uploads/";
$logsDir = __DIR__ . "/logs/";

// Create logs directory if not exists
if (!file_exists($logsDir)) {
    mkdir($logsDir, 0777, true);
}

function addLog($message, $type = "info") {
    global $logsDir;
    $logFile = $logsDir . "printer_" . date('Y-m-d') . ".log";
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] [$type] $message\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

function getQueueCount() {
    global $printer;
    // Count files in uploads directory instead of using PowerShell which may fail
    $uploadsDir = __DIR__ . "/uploads/";
    if (file_exists($uploadsDir)) {
        $files = scandir($uploadsDir);
        $count = count($files) - 2; // Subtract . and ..
        return max(0, $count);
    }
    return 0;
}

function getUploadedFiles() {
    global $uploadsDir;
    $files = [];
    $currentSession = session_id();
    
    if (file_exists($uploadsDir)) {
        $fileList = scandir($uploadsDir);
        foreach ($fileList as $file) {
            if ($file !== '.' && $file !== '..') {
                $filePath = $uploadsDir . $file;
                $status = 'ready';
                $ownerSession = null;
                
                if (isset($_SESSION['files'][$file])) {
                    $status = $_SESSION['files'][$file]['status'];
                    $ownerSession = $_SESSION['files'][$file]['owner_session'] ?? null;
                }
                
                if ($ownerSession === $currentSession) {
                    $files[] = [
                        'name' => $file,
                        'originalName' => $file,
                        'size' => filesize($filePath),
                        'uploadTime' => filemtime($filePath),
                        'status' => $status
                    ];
                }
            }
        }
    }
    return $files;
}

if ($action == 'get_files') {
    $files = getUploadedFiles();
    $queueCount = getQueueCount();
    echo json_encode([
        'success' => true, 
        'files' => $files, 
        'queue_count' => $queueCount
    ]);
    exit;
}
elseif ($action == 'delete_file') {
    $jobId = $_POST['job_id'] ?? '';
    
    if (empty($jobId)) {
        echo json_encode(['success' => false, 'message' => 'Job ID tidak ditemukan']);
        exit;
    }
    
    // Check ownership - only file owner can delete
    if (!isset($_SESSION['files'][$jobId]) || $_SESSION['files'][$jobId]['owner_session'] !== session_id()) {
        echo json_encode(['success' => false, 'message' => 'Anda tidak memiliki akses untuk menghapus file ini']);
        exit;
    }
    
    $jobFile = $uploadsDir . $jobId;
    
    if (file_exists($jobFile)) {
        if (unlink($jobFile)) {
            // Remove from session
            if (isset($_SESSION['files'][$jobId])) {
                unset($_SESSION['files'][$jobId]);
            }
            // Remove from last_job if it matches
            if (isset($_SESSION['last_job']) && $_SESSION['last_job']['job_id'] == $jobId) {
                unset($_SESSION['last_job']);
            }
            addLog("File deleted: $jobId", "delete");
            echo json_encode(['success' => true, 'message' => 'File berhasil dihapus']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Gagal menghapus file']);
        }
    } else {
        echo json_encode(['success' => true, 'message' => 'File tidak ditemukan']);
    }
    exit;
}
elseif ($action == 'print_file') {
    $jobId = $_POST['job_id'] ?? '';
    
    if (empty($jobId)) {
        echo json_encode(['success' => false, 'message' => 'Job ID tidak ditemukan']);
        exit;
    }
    
    // Check ownership - only file owner can print
    if (!isset($_SESSION['files'][$jobId]) || $_SESSION['files'][$jobId]['owner_session'] !== session_id()) {
        echo json_encode(['success' => false, 'message' => 'Anda tidak memiliki akses untuk mencetak file ini']);
        exit;
    }
    
    $jobFile = $uploadsDir . $jobId;
    
    if (!file_exists($jobFile)) {
        echo json_encode(['success' => false, 'message' => 'File tidak ditemukan']);
        exit;
    }
    
    // Get file info from session
    $filename = $jobId;
    if (isset($_SESSION['files'][$jobId])) {
        $filename = $_SESSION['files'][$jobId]['original_name'];
    }
    
    // Update status to printing
    if (isset($_SESSION['files'][$jobId])) {
        $_SESSION['files'][$jobId]['status'] = 'printing';
    }
    
    // Set as active job
    $_SESSION['last_job'] = [
        'original_name' => $filename,
        'filename' => $jobId,
        'job_id' => $jobId,
        'status' => 'printing',
        'queue_position' => 1,
        'start_time' => time()
    ];
    
    // Execute print command asynchronously
    $sumatraPdfPath = 'C:\\Users\\LENOVO\\AppData\\Local\\SumatraPDF\\SumatraPDF.exe';
    $printer = 'EPSONL121';
    
    // Properly escape backslashes for PowerShell
    $escapedJobFile = str_replace('\\', '\\\\', $jobFile);
    
    // Use properly escaped paths in PowerShell command
    $command = 'powershell -Command "Start-Process -FilePath \'' . $sumatraPdfPath . '\' -ArgumentList \'-print-to \\\\"' . $printer . '\\\\\" \\\\"' . $escapedJobFile . '\\\\"\'  -WindowStyle Hidden"';
    
    // Execute command and capture any output
    $output = shell_exec($command . ' 2>&1');
    
    if (!file_exists($jobFile)) {
        // File doesn't exist before printing - return error
        addLog("Print failed: File not found - $filename", 'error');
        $_SESSION['files'][$jobId]['status'] = 'failed';
        echo json_encode(['success' => false, 'message' => 'File tidak ditemukan atau terjadi kesalahan saat cetak']);
        exit;
    }
    
    // Log print request (file will be auto-deleted after printing)
    addLog("Print started: $filename (Session: " . session_id() . ")", 'success');
    
    echo json_encode(['success' => true, 'message' => 'Pencetakan dimulai...']);
    exit;
}
elseif ($action == 'check_status') {
    $jobId = $_POST['job_id'] ?? '';
    
    if (empty($jobId)) {
        echo json_encode(['success' => false, 'message' => 'Job ID tidak ditemukan']);
        exit;
    }
    
    $jobFile = $uploadsDir . $jobId;
    
    // Auto-delete if printing duration exceeds 30 seconds
    if (isset($_SESSION['last_job']) && $_SESSION['last_job']['job_id'] == $jobId) {
        $elapsed = time() - $_SESSION['last_job']['start_time'];
        if ($elapsed > 30) {
            // File should be deleted by now
            if (file_exists($jobFile)) {
                unlink($jobFile);
                if (isset($_SESSION['last_job'])) {
                    unset($_SESSION['last_job']);
                }
            }
        }
    }
    
    if (file_exists($jobFile)) {
        echo json_encode([
            'success' => true, 
            'completed' => false,
            'message' => 'Print dalam proses...'
        ]);
    } else {
        echo json_encode([
            'success' => true, 
            'completed' => true,
            'message' => 'Print selesai!'
        ]);
    }
    exit;
}
elseif ($action == 'get_logs') {
    $logFile = $logsDir . "printer_" . date('Y-m-d') . ".log";
    $logs = [];
    
    if (file_exists($logFile)) {
        $lines = file($logFile);
        // Get last 50 lines
        $lines = array_slice($lines, -50);
        $logs = array_map('trim', $lines);
    }
    
    echo json_encode(['success' => true, 'logs' => $logs]);
    exit;
}
elseif ($action == 'debug') {
    $files = getUploadedFiles();
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
            'filesFromDB' => $files,
            'sessionFiles' => $_SESSION['files'] ?? [],
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
