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
    
    // First, add files from the uploads directory
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
    
    // Also add files from session that have been printed but don't exist in directory anymore
    // (either 'done' or 'cancelled' status)
    if (isset($_SESSION['files']) && is_array($_SESSION['files'])) {
        foreach ($_SESSION['files'] as $fileName => $fileData) {
            // Check if this file was already added from directory
            $alreadyAdded = false;
            foreach ($files as $addedFile) {
                if ($addedFile['name'] === $fileName) {
                    $alreadyAdded = true;
                    break;
                }
            }
            
            // If not already added, add it if it's done or cancelled (file no longer in directory)
            if (!$alreadyAdded && isset($fileData['owner_session']) && 
                $fileData['owner_session'] === $currentSession &&
                ($fileData['status'] === 'done' || $fileData['status'] === 'completed' || $fileData['status'] === 'cancelled')) {
                $files[] = [
                    'name' => $fileName,
                    'originalName' => $fileData['original_name'] ?? $fileName,
                    'size' => $fileData['size'] ?? 0,
                    'uploadTime' => $fileData['upload_time'] ?? time(),
                    'status' => $fileData['status']
                ];
            }
        }
    }
    
    return $files;
}

if ($action == 'save_client_name') {
    $clientName = $_POST['client_name'] ?? 'Unknown';
    
    // Save to session
    $_SESSION['client_name'] = $clientName;
    
    // Log client registration
    addLog("Client registered: $clientName (Session: " . session_id() . ")", 'info');
    
    echo json_encode(['success' => true, 'message' => 'Nama client tersimpan']);
    exit;
}
elseif ($action == 'get_files') {
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
        // File doesn't exist in directory (completed/cancelled) - still remove from session
        if (isset($_SESSION['files'][$jobId])) {
            unset($_SESSION['files'][$jobId]);
        }
        // Remove from last_job if it matches
        if (isset($_SESSION['last_job']) && $_SESSION['last_job']['job_id'] == $jobId) {
            unset($_SESSION['last_job']);
        }
        addLog("Session file removed: $jobId", "delete");
        echo json_encode(['success' => true, 'message' => 'File berhasil dihapus']);
    }
    exit;
}
elseif ($action == 'reset_file_status') {
    $jobId = $_POST['job_id'] ?? '';
    
    if (empty($jobId)) {
        echo json_encode(['success' => false, 'message' => 'Job ID tidak ditemukan']);
        exit;
    }
    
    // Check ownership - only file owner can retry
    if (!isset($_SESSION['files'][$jobId]) || $_SESSION['files'][$jobId]['owner_session'] !== session_id()) {
        echo json_encode(['success' => false, 'message' => 'Anda tidak memiliki akses untuk mengulangi file ini']);
        exit;
    }
    
    $jobFile = $uploadsDir . $jobId;
    
    // Check if file still exists in uploads directory
    if (!file_exists($jobFile)) {
        echo json_encode(['success' => false, 'message' => 'File tidak ditemukan. Silakan upload ulang.']);
        exit;
    }
    
    // Reset status to ready
    $_SESSION['files'][$jobId]['status'] = 'ready';
    $_SESSION['files'][$jobId]['status_time'] = time();
    
    addLog("File retry prepared: $jobId (Session: " . session_id() . ")", 'info');
    
    echo json_encode(['success' => true, 'message' => 'File siap untuk dicetak ulang']);
    exit;
}
elseif ($action == 'print_file') {
    $jobId = $_POST['job_id'] ?? '';
    $clientName = $_POST['client_name'] ?? ($_SESSION['client_name'] ?? 'Unknown');
    
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
        
        // After printing is complete, wait a moment then mark as done
        // DO NOT delete file immediately - keep it for 60 seconds with countdown
        sleep(1); // Wait 1 second for printer queue to fully receive the job
        
        // Mark file as done with completion timestamp
        if (file_exists($jobFile)) {
            if (isset($_SESSION['files'][$jobId])) {
                $_SESSION['files'][$jobId]['status'] = 'done';
                $_SESSION['files'][$jobId]['status_time'] = time(); // Completion timestamp for countdown
                $_SESSION['files'][$jobId]['completed_at'] = time();
            }
            // File stays in /uploads/ directory - will auto-delete after 60 seconds
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
    addLog("Print started: $filename | Client: $clientName (Session: " . session_id() . ") - $debugInfo", 'success');
    
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
    $autoDeleteSeconds = 60;
    
    // Check and auto-cleanup files that are done for more than 60 seconds
    if (isset($_SESSION['files'][$jobId])) {
        $fileData = &$_SESSION['files'][$jobId];
        if ($fileData['status'] === 'done' && isset($fileData['status_time'])) {
            $elapsed = time() - $fileData['status_time'];
            
            // Auto-delete file if countdown expired
            if ($elapsed > $autoDeleteSeconds) {
                if (file_exists($jobFile)) {
                    @unlink($jobFile);
                }
                // Remove from session
                unset($_SESSION['files'][$jobId]);
            }
        }
    }
    
    // Check current file status
    if (file_exists($jobFile)) {
        // File still exists
        if (isset($_SESSION['files'][$jobId])) {
            $fileStatus = $_SESSION['files'][$jobId]['status'];
            
            if ($fileStatus === 'done') {
                // Calculate remaining countdown time
                $elapsed = time() - $_SESSION['files'][$jobId]['status_time'];
                $remainingTime = max(0, $autoDeleteSeconds - $elapsed);
                
                echo json_encode([
                    'success' => true,
                    'completed' => true,
                    'status' => 'done',
                    'countdown' => $remainingTime,
                    'message' => 'Print selesai!'
                ]);
            } else {
                // Still printing
                echo json_encode([
                    'success' => true,
                    'completed' => false,
                    'status' => 'printing',
                    'message' => 'Print dalam proses...'
                ]);
            }
        } else {
            // File exists but no session data - shouldn't happen
            echo json_encode([
                'success' => true,
                'completed' => false,
                'status' => 'printing',
                'message' => 'Print dalam proses...'
            ]);
        }
    } else {
        // File doesn't exist in directory anymore
        if (isset($_SESSION['files'][$jobId])) {
            $fileStatus = $_SESSION['files'][$jobId]['status'];
            if ($fileStatus !== 'done') {
                // If not already done, mark as done now
                $_SESSION['files'][$jobId]['status'] = 'done';
                $_SESSION['files'][$jobId]['status_time'] = time();
            }
        }
        
        echo json_encode([
            'success' => true,
            'completed' => true,
            'status' => 'done',
            'countdown' => 0,
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
