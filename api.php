<?php
session_start();
header('Content-Type: application/json');

// Set timezone to Indonesia (Asia/Jakarta)
date_default_timezone_set('Asia/Jakarta');

$action = $_GET['action'] ?? '';
$printer = "EPSON L120 Series";
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
                $statusTime = 0;
                
                if (isset($_SESSION['files'][$file])) {
                    $status = $_SESSION['files'][$file]['status'];
                    $ownerSession = $_SESSION['files'][$file]['owner_session'] ?? null;
                    $statusTime = $_SESSION['files'][$file]['status_time'] ?? time();
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
        $_SESSION['files'][$jobId]['status_time'] = time();
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
    $printer = 'EPSON L120 Series';
    
    // Create a temporary PowerShell script to avoid escaping issues
    $tempDir = sys_get_temp_dir();
    $scriptFile = $tempDir . 'print_' . uniqid() . '.ps1';
    
    // Create PowerShell script with properly escaped paths
    $escapedJobFile = str_replace("'", "''", $jobFile);
    $escapedPrinter = str_replace("'", "''", $printer);
    
    $scriptContent = "& 'C:\\Users\\LENOVO\\AppData\\Local\\SumatraPDF\\SumatraPDF.exe' -print-to '" . $escapedPrinter . "' '" . $escapedJobFile . "' -silent\n";
    
    // Write script to file
    if (file_put_contents($scriptFile, $scriptContent)) {
        // Execute PowerShell script
        $command = 'powershell -ExecutionPolicy Bypass -NoProfile -File "' . $scriptFile . '" 2>&1';
        $output = shell_exec($command);
        
        // Log execution output if any
        if (!empty($output)) {
            addLog("Print command output: " . trim($output), 'debug');
        }
        
        // Delete temporary script file after execution (async)
        $deleteScriptCmd = 'powershell -NoProfile -Command "Start-Sleep -Milliseconds 500; Remove-Item -Path \'' . str_replace("'", "''", $scriptFile) . '\' -Force -ErrorAction SilentlyContinue"';
        pclose(popen($deleteScriptCmd, "r"));
    } else {
        addLog("Print failed: Cannot create temp script - $filename", 'error');
        $_SESSION['files'][$jobId]['status'] = 'failed';
        echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan saat menyiapkan pencetakan']);
        exit;
    }
    
    if (!file_exists($jobFile)) {
        // File doesn't exist before printing - return error
        addLog("Print failed: File not found - $filename", 'error');
        $_SESSION['files'][$jobId]['status'] = 'failed';
        echo json_encode(['success' => false, 'message' => 'File tidak ditemukan atau terjadi kesalahan saat cetak']);
        exit;
    }
    
    // Log print request with additional debug info
    $debugInfo = "Printer: $printer | File: $jobFile | Path exists: " . (file_exists($jobFile) ? 'YES' : 'NO');
    if (!empty($output)) {
        $debugInfo .= " | Output: " . substr($output, 0, 100);
    }
    addLog("Print started: $filename (Session: " . session_id() . ") - $debugInfo", 'success');
    
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
    
    // Auto-cleanup session entries that are done for more than 30 seconds
    foreach ($_SESSION['files'] as $fileName => &$fileData) {
        if ($fileData['status'] === 'done' && isset($fileData['status_time'])) {
            $elapsed = time() - $fileData['status_time'];
            if ($elapsed > 30) {
                unset($_SESSION['files'][$fileName]);
            }
        }
    }
    unset($fileData);
    
    // Check current file status
    if (file_exists($jobFile)) {
        // File still exists - still printing or waiting
        echo json_encode([
            'success' => true, 
            'completed' => false,
            'status' => 'printing',
            'message' => 'Print dalam proses...'
        ]);
    } else {
        // File doesn't exist - print completed
        if (isset($_SESSION['files'][$jobId])) {
            $_SESSION['files'][$jobId]['status'] = 'done';
            $_SESSION['files'][$jobId]['status_time'] = time();
        }
        
        echo json_encode([
            'success' => true, 
            'completed' => true,
            'status' => 'done',
            'message' => 'Print selesai!'
        ]);
    }
    exit;
}
elseif ($action == 'cancel_print') {
    $jobId = $_POST['job_id'] ?? '';
    
    if (empty($jobId)) {
        echo json_encode(['success' => false, 'message' => 'Job ID tidak ditemukan']);
        exit;
    }
    
    $jobFile = $uploadsDir . $jobId;
    
    // Check ownership - only file owner can cancel
    if (!isset($_SESSION['files'][$jobId]) || $_SESSION['files'][$jobId]['owner_session'] !== session_id()) {
        echo json_encode(['success' => false, 'message' => 'Anda tidak memiliki akses untuk membatalkan print ini']);
        exit;
    }
    
    // If file exists, cancel the print job and delete the file
    if (file_exists($jobFile)) {
        // Try to kill SumatraPDF process for this file (best effort)
        $escapedJobFile = str_replace("'", "''", $jobFile);
        $killCommand = 'powershell -NoProfile -Command "Get-Process SumatraPDF -ErrorAction SilentlyContinue | Where-Object { $_.Name -eq \'SumatraPDF\' } | Stop-Process -Force -ErrorAction SilentlyContinue"';
        pclose(popen($killCommand, "r"));
        
        // Delete the file
        if (unlink($jobFile)) {
            // Update session
            if (isset($_SESSION['files'][$jobId])) {
                $_SESSION['files'][$jobId]['status'] = 'cancelled';
            }
            
            // Clear last job if matches
            if (isset($_SESSION['last_job']) && $_SESSION['last_job']['job_id'] == $jobId) {
                unset($_SESSION['last_job']);
            }
            
            addLog("Print cancelled: $jobId (Session: " . session_id() . ")", 'info');
            echo json_encode(['success' => true, 'message' => 'Pencetakan dibatalkan']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Gagal membatalkan print']);
        }
    } else {
        addLog("Cancel print failed: File not found - $jobId", 'error');
        echo json_encode(['success' => false, 'message' => 'File tidak ditemukan atau sudah selesai diprint']);
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
