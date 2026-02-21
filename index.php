<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Set timezone to Indonesia (Asia/Jakarta)
date_default_timezone_set('Asia/Jakarta');

// Configuration
$printer = 'EPSON L120 Series';
$uploadsDir = __DIR__ . '/uploads/';
$logsDir = __DIR__ . '/logs/';
$sumatraPdfPath = 'C:\\Users\\LENOVO\\AppData\\Local\\SumatraPDF\\SumatraPDF.exe';

if (!file_exists($uploadsDir)) mkdir($uploadsDir, 0777, true);
if (!file_exists($logsDir)) mkdir($logsDir, 0777, true);

function addLog($message, $type = 'info') {
    global $logsDir;
    $logFile = $logsDir . 'printer_' . date('Y-m-d') . '.log';
    $timestamp = date('[Y-m-d H:i:s]');
    file_put_contents($logFile, "$timestamp [$type] $message\n", FILE_APPEND);
}

$status = '';
$message = '';
$userJob = null;

if (!isset($_SESSION['files'])) {
    $_SESSION['files'] = [];
}

// Handle POST upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    $file = $_FILES['file'];
    $filename = basename($file['name']);
    $filesize = $file['size'];
    $tmpFile = $file['tmp_name'];
    $maxSize = 100 * 1024 * 1024;
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $status = 'error';
        $message = '❌ Kesalahan upload: ' . $file['error'];
        addLog("Upload error: {$filename}", 'error');
    } elseif (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'pdf') {
        $status = 'error';
        $message = '❌ Hanya file PDF yang diperbolehkan!';
        addLog("Invalid format: {$filename}", 'error');
    } elseif ($filesize > $maxSize) {
        $status = 'error';
        $message = '❌ Ukuran file melebihi 100MB';
        addLog("File too large: {$filename}", 'error');
    } else {
        // Keep original filename, add counter suffix if duplicate exists
        $destPath = $uploadsDir . $filename;
        $finalFilename = $filename;
        
        // Check for duplicate filename
        $counter = 1;
        $baseFilename = pathinfo($filename, PATHINFO_FILENAME);
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        while (file_exists($destPath)) {
            $finalFilename = $baseFilename . ' (' . $counter . ').' . $extension;
            $destPath = $uploadsDir . $finalFilename;
            $counter++;
        }
        
        if (move_uploaded_file($tmpFile, $destPath)) {
            $_SESSION['files'][$finalFilename] = [
                'original_name' => $finalFilename,
                'size' => $filesize,
                'upload_time' => date('Y-m-d H:i:s'),
                'status' => 'ready',
                'filename' => $finalFilename,
                'owner_session' => session_id()
            ];
            
            $clientName = $_SESSION['client_name'] ?? 'Unknown';
            addLog("File uploaded: {$finalFilename} | Client: $clientName (Session: " . session_id() . ")", 'success');
            
            $status = 'success';
            $message = '✓ File berhasil di-upload! Silakan cetak file dari daftar antrian di bawah.';
        } else {
            $status = 'error';
            $message = '❌ Gagal menyimpan file';
            addLog("Failed to save file: {$filename}", 'error');
        }
    }
}

if (isset($_SESSION['last_job'])) {
    $userJob = $_SESSION['last_job'];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FIK Smart Print Server - Professional Print Management</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary-color: #6366f1;
            --secondary-color: #8b5cf6;
            --success-color: #10b981;
            --danger-color: #ef4444;
            --warning-color: #f59e0b;
            --info-color: #3b82f6;
            --light-bg: #f8fafc;
            --dark-bg: #1e293b;
            --border-color: #e2e8f0;
            --text-dark: #1e293b;
            --text-light: #64748b;
        }

        body {
            font-family: 'Segoe UI', 'Roboto', 'Helvetica', 'Arial', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            color: var(--text-dark);
        }

        .container {
            width: 100%;
            max-width: 1200px;
        }

        .card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
            animation: slideUp 0.5s ease-out;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
            padding: 40px;
            text-align: center;
        }

        .header h1 {
            font-size: 32px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
        }

        .header p {
            font-size: 14px;
            opacity: 0.9;
            margin-bottom: 15px;
        }

        .status-indicators {
            display: flex;
            justify-content: center;
            gap: 20px;
            flex-wrap: wrap;
            margin-top: 15px;
        }

        .status-indicator {
            background: rgba(255, 255, 255, 0.2);
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            backdrop-filter: blur(10px);
        }

        .status-indicator strong {
            color: #fff;
        }

        .main-content {
            padding: 40px;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideIn 0.3s ease-out;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(-20px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .alert.success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .alert.error {
            background: #fee2e2;
            color: #7f1d1d;
            border: 1px solid #fecaca;
        }

        .section-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-dark);
            margin: 30px 0 20px 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .upload-box {
            border: 2px dashed var(--primary-color);
            border-radius: 12px;
            padding: 40px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            background: var(--light-bg);
            position: relative;
        }

        .upload-box:hover {
            border-color: var(--secondary-color);
            background: #f1f0ff;
        }

        .upload-box.active {
            border-color: var(--success-color);
            background: #ecfdf5;
        }

        .upload-box input[type="file"] {
            display: none;
        }

        .upload-icon {
            font-size: 48px;
            margin-bottom: 15px;
            display: block;
        }

        .upload-text {
            font-size: 16px;
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 5px;
        }

        .upload-hint {
            font-size: 14px;
            color: var(--text-light);
        }

        .file-preview-box {
            background: white;
            border: 1px solid var(--success-color);
            border-radius: 12px;
            padding: 20px;
            margin: 15px 0;
            animation: slideIn 0.3s ease-out;
        }

        .preview-content {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .preview-icon {
            font-size: 32px;
            color: var(--success-color);
            flex-shrink: 0;
        }

        .preview-filename {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-dark);
            word-break: break-word;
            text-align: left;
        }

        .preview-filesize {
            font-size: 12px;
            color: var(--text-light);
            text-align: left;
        }

        .preview-actions {
            display: flex;
            gap: 10px;
            margin-left: auto;
            flex-shrink: 0;
        }

        .btn-preview-view,
        .btn-preview-delete {
            background: none;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.2s ease;
            color: var(--text-dark);
        }

        .btn-preview-view:hover {
            background: var(--info-color);
            color: white;
            border-color: var(--info-color);
        }

        .btn-preview-delete:hover {
            background: var(--danger-color);
            color: white;
            border-color: var(--danger-color);
        }

        .file-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }

        .file-card {
            background: white;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .file-card:hover {
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.12);
            transform: translateY(-5px);
            border-color: var(--primary-color);
        }

        .file-icon {
            font-size: 48px;
            margin-bottom: 12px;
            display: block;
        }

        .file-name {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-dark);
            word-break: break-word;
            margin-bottom: 8px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            height: 32px;
        }

        .file-size {
            font-size: 12px;
            color: var(--text-light);
            margin-bottom: 10px;
        }

        .file-status {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            margin-bottom: 12px;
        }

        .file-status.ready {
            background: #dbeafe;
            color: #1e40af;
        }

        .file-status.printing {
            background: #fef3c7;
            color: #92400e;
            animation: pulse 1.5s infinite;
        }

        .file-status.completed {
            background: #d1fae5;
            color: #065f46;
        }

        .file-status.done {
            background: #d1fae5;
            color: #065f46;
        }

        .file-status.cancelled {
            background: #fee2e2;
            color: #991b1b;
        }

        .file-countdown {
            font-size: 12px;
            color: var(--warning-color);
            font-weight: 600;
            margin-top: 8px;
            padding: 8px;
            background: rgba(245, 158, 11, 0.1);
            border-radius: 6px;
        }

        @keyframes pulse {
            0%, 100% {
                opacity: 1;
            }
            50% {
                opacity: 0.7;
            }
        }

        .file-actions {
            display: flex;
            gap: 8px;
            margin-top: 12px;
        }

        .btn {
            flex: 1;
            padding: 8px 12px;
            border: none;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .btn-print {
            background: var(--success-color);
            color: white;
        }

        .btn-print:hover {
            background: #059669;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }

        .btn-print:disabled {
            background: #cbd5e1;
            color: #64748b;
            cursor: not-allowed;
            box-shadow: none;
        }

        .btn-cancel {
            background: var(--danger-color);
            color: white;
        }

        .btn-cancel:hover {
            background: #dc2626;
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
        }

        .btn-delete {
            background: var(--danger-color);
            color: white;
        }

        .btn-delete:hover {
            background: #dc2626;
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
        }

        .btn-retry {
            background: #f59e0b;
            color: white;
        }

        .btn-retry:hover {
            background: #d97706;
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
        }

        .btn-upload {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
            padding: 12px 30px;
            margin-top: 20px;
            border-radius: 8px;
            font-size: 14px;
            display: inline-block;
            cursor: pointer;
        }

        .btn-upload:hover {
            box-shadow: 0 6px 20px rgba(99, 102, 241, 0.4);
            transform: translateY(-2px);
        }

        .btn-upload:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .logs-container {
            background: #1e293b;
            color: #e2e8f0;
            padding: 20px;
            border-radius: 12px;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            max-height: 300px;
            overflow-y: auto;
            margin: 20px 0;
        }

        .log-entry {
            padding: 8px 10px;
            border-bottom: 1px solid #334155;
            line-height: 1.5;
            border-left: 4px solid #334155;
            margin-bottom: 2px;
            transition: all 0.2s ease;
        }

        .log-entry:hover {
            background-color: rgba(100, 116, 139, 0.3);
        }

        .log-entry:last-child {
            border-bottom: none;
        }

        .log-entry.success {
            color: #10b981;
        }

        .log-entry.error {
            color: #ef4444;
        }

        .log-entry.info {
            color: #3b82f6;
        }

        /* Client color variants - left border indicates client */
        .log-entry[data-client-color="color1"] {
            border-left-color: #ff6b6b;
        }
        .log-entry[data-client-color="color2"] {
            border-left-color: #4ecdc4;
        }
        .log-entry[data-client-color="color3"] {
            border-left-color: #ffd93d;
        }
        .log-entry[data-client-color="color4"] {
            border-left-color: #6bcf7f;
        }
        .log-entry[data-client-color="color5"] {
            border-left-color: #a29bfe;
        }
        .log-entry[data-client-color="color6"] {
            border-left-color: #fd79a8;
        }
        .log-entry[data-client-color="color7"] {
            border-left-color: #55efc4;
        }
        .log-entry[data-client-color="color8"] {
            border-left-color: #fab1a0;
        }

        /* Client font color variants */
        .log-entry[data-client-color="color1"] .client-name {
            color: #ff6b6b;
            font-weight: 600;
        }
        .log-entry[data-client-color="color2"] .client-name {
            color: #4ecdc4;
            font-weight: 600;
        }
        .log-entry[data-client-color="color3"] .client-name {
            color: #ffd93d;
            font-weight: 600;
        }
        .log-entry[data-client-color="color4"] .client-name {
            color: #6bcf7f;
            font-weight: 600;
        }
        .log-entry[data-client-color="color5"] .client-name {
            color: #a29bfe;
            font-weight: 600;
        }
        .log-entry[data-client-color="color6"] .client-name {
            color: #fd79a8;
            font-weight: 600;
        }
        .log-entry[data-client-color="color7"] .client-name {
            color: #55efc4;
            font-weight: 600;
        }
        .log-entry[data-client-color="color8"] .client-name {
            color: #fab1a0;
            font-weight: 600;
        }

        .empty-state {
            text-align: center;
            padding: 60px 40px;
            color: var(--text-light);
            grid-column: 1/-1;
            background: rgba(148, 163, 184, 0.1);
            border-radius: 12px;
            border: 2px dashed var(--border-color);
        }

        .empty-icon {
            font-size: 80px;
            margin-bottom: 20px;
            opacity: 0.4;
            color: var(--text-light);
        }

        .empty-state div:last-child {
            font-size: 18px;
            font-weight: 500;
            color: var(--text-dark);
        }

        footer {
            background: var(--light-bg);
            padding: 20px;
            text-align: center;
            font-size: 12px;
            color: var(--text-light);
            border-top: 1px solid var(--border-color);
        }

        @media (max-width: 768px) {
            .header {
                padding: 30px 20px;
            }

            .header h1 {
                font-size: 24px;
            }

            .main-content {
                padding: 20px;
            }

            .file-grid {
                grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            }

            .status-indicators {
                flex-direction: column;
                gap: 10px;
            }
        }

        button:disabled {
            opacity: 0.6 !important;
            cursor: not-allowed !important;
        }

        .spinner {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 0.6s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        /* Info Button & Section Title */
        .section-title {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            position: relative;
            margin-bottom: 20px;
        }

        .section-title-content {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .btn-info {
            background: none;
            border: none;
            color: var(--info-color);
            font-size: 18px;
            cursor: pointer;
            padding: 5px 10px;
            border-radius: 50%;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            position: absolute;
            right: 0;
            top: 0;
        }

        .btn-info:hover {
            background: rgba(59, 130, 246, 0.1);
            color: var(--primary-color);
            transform: scale(1.2);
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            animation: fadeIn 0.3s ease;
        }

        .modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }

        .modal-content {
            background-color: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            max-width: 700px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
            animation: slideUp 0.3s ease-out;
        }

        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 25px;
            border-bottom: 2px solid var(--border-color);
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
            border-radius: 16px 16px 0 0;
        }

        .modal-header h2 {
            margin: 0;
            font-size: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modal-close {
            background: none;
            border: none;
            color: white;
            font-size: 24px;
            cursor: pointer;
            padding: 0;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.3s ease;
        }

        .modal-close:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: rotate(90deg);
        }

        .modal-body {
            padding: 25px;
        }

        .rules-section {
            margin-bottom: 25px;
        }

        .rules-section h3 {
            color: var(--primary-color);
            font-size: 16px;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .rules-section ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .rules-section li {
            padding: 8px 0;
            padding-left: 20px;
            color: var(--text-dark);
            position: relative;
            line-height: 1.6;
        }

        .rules-section li:before {
            content: "✓";
            position: absolute;
            left: 0;
            color: var(--success-color);
            font-weight: bold;
        }

        .info-box {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.1) 0%, rgba(139, 92, 246, 0.1) 100%);
            border-left: 4px solid var(--info-color);
            padding: 15px;
            border-radius: 8px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }

        .info-box i {
            color: var(--info-color);
            font-size: 20px;
            margin-top: 2px;
            flex-shrink: 0;
        }

        .info-box p {
            margin: 0;
            color: var(--text-dark);
            line-height: 1.6;
        }

        .modal-footer {
            padding: 20px 25px;
            border-top: 1px solid var(--border-color);
            display: flex;
            gap: 10px;
            justify-content: center;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
            padding: 10px 30px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(99, 102, 241, 0.3);
        }

        /* Modal Size Variants */
        .modal-sm .modal-content {
            max-width: 450px;
        }

        /* Input Field Styling */
        .input-field {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
            transition: all 0.3s ease;
            margin-bottom: 15px;
        }

        .input-field:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }

        /* Full Width Button */
        .btn-full {
            width: 100%;
        }

        /* Tour Steps */
        .tour-step {
            margin-bottom: 25px;
            padding: 20px;
            background: #f8fafc;
            border-radius: 12px;
            border-left: 4px solid var(--primary-color);
            display: flex;
            gap: 15px;
            position: relative;
        }

        .tour-step-number {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
            border-radius: 50%;
            font-weight: bold;
            font-size: 18px;
            flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
        }

        .tour-step h3 {
            margin: 0 0 8px 0;
            color: var(--primary-color);
            font-size: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .tour-step p {
            margin: 0;
            color: var(--text-dark);
            font-size: 13px;
            line-height: 1.6;
        }

        .tour-step.info-box {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.1) 0%, rgba(139, 92, 246, 0.1) 100%);
            border-left: 4px solid var(--info-color);
        }

        /* Client Name Display */
        .client-name-badge {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-left: 10px;
        }

        /* Guided Tour Styles */
        #guidedTourModal {
            display: none;
            position: fixed;
            z-index: 999;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
        }

        #guidedTourModal.show {
            display: block;
        }

        .guided-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            z-index: 999;
            animation: fadeIn 0.4s ease;
        }

        .guided-spotlight {
            position: fixed;
            border: 3px solid var(--primary-color);
            border-radius: 50%;
            background: rgba(99, 102, 241, 0.1);
            box-shadow: 0 0 20px rgba(99, 102, 241, 0.5), inset 0 0 20px rgba(99, 102, 241, 0.2);
            z-index: 1000;
            animation: pulse-glow 2s ease-in-out infinite;
        }

        @keyframes pulse-glow {
            0%, 100% {
                box-shadow: 0 0 20px rgba(99, 102, 241, 0.5), inset 0 0 20px rgba(99, 102, 241, 0.2);
            }
            50% {
                box-shadow: 0 0 40px rgba(99, 102, 241, 0.8), inset 0 0 30px rgba(99, 102, 241, 0.3);
            }
        }

        @keyframes pulse-highlight {
            0% {
                transform: scale(1);
                box-shadow: 0 0 0 0 rgba(99, 102, 241, 0.7);
            }
            50% {
                transform: scale(1.1);
                box-shadow: 0 0 0 10px rgba(99, 102, 241, 0);
            }
            100% {
                transform: scale(1);
                box-shadow: 0 0 0 0 rgba(99, 102, 241, 0);
            }
        }

        .guided-tooltip {
            position: fixed;
            z-index: 1001;
            animation: slideUp 0.4s ease-out;
        }

        .guided-tooltip-content {
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            padding: 20px;
            max-width: 350px;
            border-left: 4px solid var(--primary-color);
        }

        .guided-tooltip-content h3 {
            margin: 0 0 12px 0;
            color: var(--primary-color);
            font-size: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .guided-tooltip-content p {
            margin: 0;
            color: var(--text-dark);
            font-size: 14px;
            line-height: 1.6;
        }

        .guided-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }

        .guided-actions .btn {
            flex: 1;
            padding: 8px 12px;
            font-size: 12px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .guided-actions .btn-primary {
            background: var(--border-color);
            color: var(--text-light);
        }

        .guided-actions .btn-primary:hover {
            background: #cbd5e1;
        }

        .guided-actions .btn-info {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
        }

        .guided-actions .btn-info:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(99, 102, 241, 0.3);
        }

        /* Arrow pointing to button */
        .guided-tooltip::before {
            content: '';
            position: absolute;
            width: 12px;
            height: 12px;
            background: white;
            border-left: 2px solid var(--primary-color);
            border-bottom: 2px solid var(--primary-color);
            transform: rotate(45deg);
            z-index: 1002;
        }

        @media (max-width: 768px) {
            .modal-content {
                width: 95%;
                max-height: 90vh;
            }

            .modal-header h2 {
                font-size: 18px;
            }

            .modal-body {
                padding: 20px;
            }

            .rules-section {
                margin-bottom: 20px;
            }
        }
    </style>
</head>
<body>

<div class="container">
    <div class="card">
        <!-- Header -->
        <div class="header">
            <h1>
                <i class="fas fa-print"></i>
                FIK Smart Print Server
            </h1>
            <p>Professional Print Queue Management System</p>
            <div class="status-indicators">
                <div class="status-indicator">
                    <strong>Queue:</strong> <span id="queueCount">0</span>
                </div>
                <div class="status-indicator">
                    <strong>Status:</strong> <span id="systemStatus">Ready</span>
                </div>
                <div class="status-indicator">
                    <strong>Server:</strong> <span id="currentTime">Loading...</span>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Message Alert -->
            <?php if (!empty($message)) { ?>
            <div class="alert <?php echo $status; ?>">
                <i class="fas fa-<?php echo $status === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
            <?php } ?>

            <!-- Upload Section -->
            <div class="section-title">
                <div class="section-title-content">
                    <i class="fas fa-cloud-upload-alt"></i>
                    Upload File PDF
                </div>
                <button class="btn-info" id="infoBtn" title="Lihat syarat dan ketentuan">
                    <i class="fas fa-question-circle"></i>
                </button>
            </div>
            <form id="uploadForm" enctype="multipart/form-data">
                <div class="upload-box" id="uploadBox">
                    <input type="file" id="fileInput" name="file" accept=".pdf" required>
                    <span class="upload-icon"><i class="fas fa-file-pdf"></i></span>
                    <div class="upload-text">Pilih atau Drag File PDF</div>
                    <div class="upload-hint">Maksimal 100MB | Format: PDF</div>
                </div>
                <!-- File Preview Box -->
                <div class="file-preview-box" id="filePreviewBox" style="display: none;">
                    <div class="preview-content">
                        <i class="fas fa-file-pdf preview-icon"></i>
                        <div>
                            <div class="preview-filename" id="previewFilename"></div>
                            <div class="preview-filesize" id="previewFilesize"></div>
                        </div>
                        <div class="preview-actions">
                            <button type="button" class="btn-preview-view" id="previewViewBtn" title="Lihat File">
                                <i class="fas fa-eye"></i>
                            </button>
                            <button type="button" class="btn-preview-delete" id="previewDeleteBtn" title="Hapus Pilihan">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                </div>
                <button type="submit" class="btn-upload" id="uploadBtn" style="display: none;">
                    <i class="fas fa-arrow-up"></i> Upload File
                </button>
            </form>

            <!-- Print Queue Section -->
            <div class="section-title">
                <div class="section-title-content">
                    <i class="fas fa-list"></i>
                    Antrian Cetak
                </div>
            </div>
            <div class="file-grid" id="fileGrid">
                <div class="empty-state">
                    <div class="empty-icon"><i class="fas fa-inbox"></i></div>
                    <div>Tidak ada file dalam antrian</div>
                </div>
            </div>

            <!-- Logs Section -->
            <div class="section-title">
                <div class="section-title-content">
                    <i class="fas fa-history"></i>
                    Log Aktivitas
                </div>
            </div>
            <div class="logs-container" id="logsContainer">
                <div class="log-entry info">⏳ Memuat log aktivitas...</div>
            </div>
        </div>

        <!-- Client Name Input Modal -->
        <div class="modal" id="nameModal">
            <div class="modal-content modal-sm">
                <div class="modal-header">
                    <h2><i class="fas fa-user"></i> Daftarkan Nama Anda</h2>
                </div>
                <div class="modal-body">
                    <p style="margin-bottom: 20px; text-align: center; color: var(--text-light);">
                        Selamat datang! Silakan masukkan nama Anda untuk memulai penggunaan sistem print server ini.
                    </p>
                    <form id="nameForm">
                        <input 
                            type="text" 
                            id="clientName" 
                            class="input-field" 
                            placeholder="Masukkan nama Anda..." 
                            required 
                            autocomplete="off"
                        >
                        <button type="submit" class="btn btn-primary btn-full">
                            <i class="fas fa-check"></i> Lanjutkan
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Tour/Arahan Penggunaan Modal -->
        <div class="modal" id="tourModal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2><i class="fas fa-graduation-cap"></i> Panduan Penggunaan Sistem</h2>
                    <button class="modal-close" onclick="closeTourModal()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="tour-step">
                        <div class="tour-step-number">1</div>
                        <h3><i class="fas fa-cloud-upload-alt"></i> Upload File PDF</h3>
                        <p>Klik area "Upload File PDF" atau drag-drop file PDF yang ingin Anda cetak. Pastikan file berformat PDF dan ukuran tidak melebihi 100MB.</p>
                    </div>

                    <div class="tour-step">
                        <div class="tour-step-number">2</div>
                        <h3><i class="fas fa-eye"></i> Preview File</h3>
                        <p>Setelah memilih file, Anda dapat preview file dengan mengklik ikon mata. Pastikan tampilan file sudah sesuai sebelum mencetak.</p>
                    </div>

                    <div class="tour-step">
                        <div class="tour-step-number">3</div>
                        <h3><i class="fas fa-upload"></i> Konfirmasi Upload</h3>
                        <p>Klik tombol "Upload File" untuk mengirim file ke server untuk diproses. Tunggu hingga file muncul di daftar "Antrian Cetak".</p>
                    </div>

                    <div class="tour-step">
                        <div class="tour-step-number">4</div>
                        <h3><i class="fas fa-print"></i> Cetak File</h3>
                        <p>File yang sudah diupload akan muncul di "Antrian Cetak" dengan status "● READY". Klik tombol "Print" untuk mulai mencetak.</p>
                    </div>

                    <div class="tour-step">
                        <div class="tour-step-number">5</div>
                        <h3><i class="fas fa-spinner"></i> Monitor Status</h3>
                        <p>Saat printing berlangsung, status akan berubah menjadi "⟳ PRINTING". Anda dapat membatalkan dengan mengklik tombol "Cancel" jika diperlukan.</p>
                    </div>

                    <div class="tour-step">
                        <div class="tour-step-number">6</div>
                        <h3><i class="fas fa-check-circle"></i> Selesai</h3>
                        <p>Setelah selesai, status berubah menjadi "✓ DONE". File akan otomatis dihapus dari antrian setelah 30 detik. Ambil hasil cetakan dari printer.</p>
                    </div>

                    <div class="tour-step info-box" style="margin-top: 20px;">
                        <i class="fas fa-lightbulb"></i>
                        <p><strong>Tips Penting:</strong> <br>
                        • Periksa kembali pembaca ketentuan sebelum mencetak<br>
                        • Gunakan file PDF dengan resolusi 300 DPI untuk hasil terbaik<br>
                        • Jangan menutup halaman saat proses pencetakan</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-primary" onclick="closeTourModal()">
                        <i class="fas fa-check"></i> Saya Sudah Mengerti
                    </button>
                </div>
            </div>
        </div>

        <!-- Guided Tour Modal (Highlight Info Button) -->
        <div class="modal" id="guidedTourModal">
            <div class="guided-overlay"></div>
            <div class="guided-spotlight"></div>
            <div class="guided-tooltip">
                <div class="guided-tooltip-content">
                    <h3><i class="fas fa-mouse-pointer"></i> Penting!</h3>
                    <p>Klik tombol <strong>(?)</strong> di samping kanan "Upload File PDF" untuk membaca <strong>Syarat & Ketentuan Pencetakan</strong> sebelum mencetak.</p>
                    <p style="font-size: 12px; color: var(--text-light); margin-top: 10px;">Ini wajib dibaca minimal sekali sebelum Anda bisa melakukan pencetakan.</p>
                    <div class="guided-actions">
                        <button class="btn btn-primary" onclick="skipGuidedTour()">
                            <i class="fas fa-forward"></i> Lewati Arahan
                        </button>
                        <button class="btn btn-info" onclick="focusInfoButton()">
                            <i class="fas fa-check"></i> Klik Tombol (?)
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Rules Information Modal -->
        <div class="modal" id="rulesModal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2><i class="fas fa-info-circle"></i> Syarat & Ketentuan Pencetakan</h2>
                    <button class="modal-close" onclick="closeRulesModal()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="rules-section">
                        <h3><i class="fas fa-file-pdf"></i> Format File</h3>
                        <ul>
                            <li>Hanya file <strong>PDF</strong> yang diperbolehkan</li>
                            <li>Ukuran file maksimal <strong>100 MB</strong></li>
                            <li>Pastikan file PDF sudah valid dan tidak corrupt</li>
                        </ul>
                    </div>

                    <div class="rules-section">
                        <h3><i class="fas fa-file-alt"></i> Kertas & Ukuran</h3>
                        <ul>
                            <li>Gunakan kertas standar <strong>A4 (210 x 297 mm)</strong></li>
                            <li>PDF harus disetting untuk ukuran halaman A4</li>
                            <li>Hindari scaling otomatis yang dapat menyebabkan pemotongan</li>
                            <li>Margin minimal: 0,5 cm dari tepi kertas</li>
                        </ul>
                    </div>

                    <div class="rules-section">
                        <h3><i class="fas fa-palette"></i> Kualitas & Warna</h3>
                        <ul>
                            <li>Gunakan <strong>resolusi minimal 300 DPI</strong> untuk hasil terbaik</li>
                            <li>Cetak warna: Gunakan mode <strong>RGB atau CMYK</strong> yang tepat</li>
                            <li>Pastikan semua text dan gambar terlihat jelas di preview</li>
                            <li>Hindari menggunakan font custom yang tidak embedded</li>
                        </ul>
                    </div>

                    <div class="rules-section">
                        <h3><i class="fas fa-cogs"></i> Pengaturan & Proses</h3>
                        <ul>
                            <li>Sistem akan otomatis memproses file sesuai urutan antrian</li>
                            <li>Waktu proses cetak: <strong>5 - 30 detik</strong> tergantung ukuran file</li>
                            <li>Anda dapat membatalkan cetak sebelum proses selesai</li>
                            <li>File otomatis dihapus setelah pencetakan selesai</li>
                        </ul>
                    </div>

                    <div class="rules-section">
                        <h3><i class="fas fa-exclamation-triangle"></i> Batasan & Larangan</h3>
                        <ul>
                            <li>Dilarang mengupload file yang bukan PDF</li>
                            <li>Dilarang mengupload file dengan konten pornografi atau ilegal</li>
                            <li>Maksimal print per hari: <strong>100 halaman</strong></li>
                            <li>Tunggu antrian sebelumnya selesai sebelum cetak file baru</li>
                        </ul>
                    </div>

                    <div class="rules-section info-box">
                        <i class="fas fa-lightbulb"></i>
                        <p><strong>Tips:</strong> Untuk hasil terbaik, gunakan aplikasi Adobe Reader atau PDF viewer profesional lainnya untuk preview file sebelum mencetak.</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-primary" onclick="closeRulesModal()">
                        <i class="fas fa-check"></i> Saya Mengerti
                    </button>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <footer>
            <i class="fas fa-server"></i> FIK Print Server | Printer: <strong>EPSON L120 Series</strong> | 
            <span id="footerTime">20/02/2026 22:39:25</span>
        </footer>
    </div>
</div>

<script>
    const fileInput = document.getElementById('fileInput');
    const uploadBox = document.getElementById('uploadBox');
    const uploadBtn = document.getElementById('uploadBtn');
    const uploadForm = document.getElementById('uploadForm');
    const fileGrid = document.getElementById('fileGrid');
    const logsContainer = document.getElementById('logsContainer');
    const queueCountSpan = document.getElementById('queueCount');
    const systemStatusSpan = document.getElementById('systemStatus');
    const currentTimeSpan = document.getElementById('currentTime');
    const footerTimeSpan = document.getElementById('footerTime');
    const rulesModal = document.getElementById('rulesModal');
    const infoBtn = document.getElementById('infoBtn');
    const nameModal = document.getElementById('nameModal');
    const tourModal = document.getElementById('tourModal');
    const nameForm = document.getElementById('nameForm');
    const clientNameInput = document.getElementById('clientName');

    // Client name tracking
    let clientName = localStorage.getItem('clientName');
    let hasReadRules = localStorage.getItem('hasReadRules') === 'true';
    let hasSeenGuide = localStorage.getItem('hasSeenGuide') === 'true';
    let currentPrintingFile = null; // Track currently printing file
    let printStatusCheckInterval = null;
    let normalUpdateInterval = null;
    let logsOnlyInterval = null;
    let countdownTimers = {}; // Track countdown timers for done files

    // Modal Functions
    function openRulesModal() {
        rulesModal.classList.add('show');
    }

    function closeRulesModal() {
        rulesModal.classList.remove('show');
        // Mark as read when modal is closed
        localStorage.setItem('hasReadRules', 'true');
        hasReadRules = true;
    }

    function openTourModal() {
        tourModal.classList.add('show');
    }

    function closeTourModal() {
        tourModal.classList.remove('show');
        // Show guided tour if user hasn't seen it yet
        if (!hasSeenGuide) {
            setTimeout(() => {
                openGuidedTour();
            }, 500);
        }
    }

    function openNameModal() {
        nameModal.classList.add('show');
    }

    function closeNameModal() {
        nameModal.classList.remove('show');
    }

    // Guided Tour Functions
    const guidedTourModal = document.getElementById('guidedTourModal');
    let guidedTourActive = false;

    function openGuidedTour() {
        guidedTourModal.classList.add('show');
        guidedTourActive = true;
        highlightInfoButton();
    }

    function closeGuidedTour() {
        guidedTourModal.classList.remove('show');
        guidedTourActive = false;
    }

    function highlightInfoButton() {
        if (!infoBtn) return;
        
        const rect = infoBtn.getBoundingClientRect();
        const spotlight = document.querySelector('.guided-spotlight');
        const tooltip = document.querySelector('.guided-tooltip');
        
        // Position spotlight
        const spotlightSize = 120;
        spotlight.style.left = (rect.left + rect.width / 2 - spotlightSize / 2) + 'px';
        spotlight.style.top = (rect.top + rect.height / 2 - spotlightSize / 2) + 'px';
        spotlight.style.width = spotlightSize + 'px';
        spotlight.style.height = spotlightSize + 'px';
        
        // Position tooltip
        tooltip.style.left = (rect.left - 200) + 'px';
        tooltip.style.top = (rect.top - 180) + 'px';
    }

    function skipGuidedTour() {
        closeGuidedTour();
        localStorage.setItem('hasSeenGuide', 'true');
    }

    function focusInfoButton() {
        closeGuidedTour();
        localStorage.setItem('hasSeenGuide', 'true');
        // Highlight and auto-click or focus on the button
        infoBtn.style.animation = 'pulse-highlight 0.6s ease-in-out';
        setTimeout(() => {
            openRulesModal();
        }, 150);
    }

    // Reposition spotlight on window resize
    window.addEventListener('resize', () => {
        if (guidedTourActive) {
            highlightInfoButton();
        }
    });

    // Close modal when clicking outside of it
    window.addEventListener('click', (event) => {
        if (event.target === rulesModal) {
            closeRulesModal();
        }
        if (event.target === tourModal) {
            // Don't allow closing tour by clicking outside
            return;
        }
    });

    // Close modal with Escape key
    window.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            if (rulesModal.classList.contains('show')) {
                closeRulesModal();
            }
        }
    });

    // Name form submission
    nameForm.addEventListener('submit', (e) => {
        e.preventDefault();
        const name = clientNameInput.value.trim();
        if (name.length > 0) {
            clientName = name;
            localStorage.setItem('clientName', clientName);
            closeNameModal();
            
            // Show tour modal
            setTimeout(() => {
                openTourModal();
            }, 300);
            
            // Send name to server to save in database
            fetch('api.php?action=save_client_name', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'client_name=' + encodeURIComponent(clientName)
            }).catch(err => console.error('Error saving client name:', err));
        }
    });

    // Info button click event
    infoBtn.addEventListener('click', (e) => {
        e.preventDefault();
        openRulesModal();
    });

    // Check if client is new and show name modal
    function checkClientStatus() {
        if (!clientName) {
            openNameModal();
        }
    }

    // Format file size
    function formatFileSize(bytes) {
        if (bytes === 0) return '0 B';
        const k = 1024;
        const sizes = ['B', 'KB', 'MB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return Math.round((bytes / Math.pow(k, i)) * 100) / 100 + ' ' + sizes[i];
    }

    // Escape HTML
    function htmlEscape(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Get color ID for client based on name hash
    function getClientColorClass(clientName) {
        if (!clientName || clientName === 'Unknown') {
            return 'color-neutral';
        }
        
        // Simple hash function to get consistent color for same client
        let hash = 0;
        for (let i = 0; i < clientName.length; i++) {
            hash = ((hash << 5) - hash) + clientName.charCodeAt(i);
            hash = hash & hash; // Convert to 32bit integer
        }
        
        // Map hash to 8 colors
        const colorIndex = Math.abs(hash) % 8 + 1;
        return `color${colorIndex}`;
    }

    // Extract client name from log entry
    function extractClientName(logEntry) {
        const clientMatch = logEntry.match(/Client:\s*([^|]+?)(?:\s*$|\s*\|)/);
        if (clientMatch) {
            return clientMatch[1].trim();
        }
        return null;
    }

    // Format log entry with colored client name (safely)
    function formatLogEntry(logEntry, clientName) {
        if (!clientName) {
            return htmlEscape(logEntry);
        }
        
        // Find and replace the "Client: [name]" part with colored version
        const clientPattern = new RegExp(`Client:\\s*${escapeRegExp(clientName)}(?=\\s*$|\\s*\\|)`);
        const parts = logEntry.split(clientPattern);
        
        if (parts.length === 2) {
            // Client name was found - reassemble with coloring
            return htmlEscape(parts[0]) + 'Client: <span class="client-name">' + 
                   htmlEscape(clientName) + '</span>' + htmlEscape(parts[1]);
        } else {
            // Client name not found - just escape
            return htmlEscape(logEntry);
        }
    }

    // Escape special regex characters
    function escapeRegExp(string) {
        return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    // Update file grid
    function startCountdownTimer(filename, remainingSeconds) {
        // Clear existing timer for this file
        if (countdownTimers[filename]) {
            clearInterval(countdownTimers[filename]);
        }
        
        // Don't start timer if already expired
        if (remainingSeconds <= 0) {
            return;
        }
        
        let remaining = remainingSeconds;
        const countdownElement = document.getElementById(`countdown-${filename}`);
        
        if (!countdownElement) {
            return; // Element doesn't exist yet
        }
        
        // Update countdown display immediately
        function updateDisplay() {
            const mins = Math.floor(remaining / 60);
            const secs = remaining % 60;
            const timeStr = `${mins}:${secs.toString().padStart(2, '0')}`;
            countdownElement.innerHTML = `<i class="fas fa-hourglass-end"></i> Akan dihapus dalam ${timeStr}...`;
            
            if (remaining <= 0) {
                clearInterval(countdownTimers[filename]);
                delete countdownTimers[filename];
                // Refresh grid after countdown expires
                updateFileGrid();
            }
        }
        
        updateDisplay();
        
        // Update every second
        countdownTimers[filename] = setInterval(() => {
            remaining--;
            updateDisplay();
        }, 1000);
    }

    // Update file grid
    function updateFileGrid() {
        fetch('api.php?action=get_files')
            .then(res => res.json())
            .then(data => {
                if (data.success && data.files && data.files.length > 0) {
                    queueCountSpan.textContent = data.queue_count;
                    systemStatusSpan.textContent = data.queue_count > 0 ? 'Printing' : 'Ready';
                    
                    fileGrid.innerHTML = data.files.map(file => {
                        // Determine button display based on status
                        let actionButtons = '';
                        const isPrinting = file.status === 'printing';
                        const isDone = file.status === 'completed' || file.status === 'done';
                        const isCancelled = file.status === 'cancelled';
                        const isFailed = file.status === 'failed';
                        
                        if (isPrinting) {
                            // Show Cancel button only for printing files
                            actionButtons = `
                                <button class="btn btn-cancel" onclick="cancelPrint('${htmlEscape(file.name)}')">
                                    <i class="fas fa-times"></i> Cancel
                                </button>
                            `;
                        } else if (isDone) {
                            // Show Print Again and Delete for completed files
                            actionButtons = `
                                <button class="btn btn-print" id="print-${htmlEscape(file.name)}" onclick="printFile('${htmlEscape(file.name)}')">
                                    <i class="fas fa-print"></i> Print Lagi
                                </button>
                                <button class="btn btn-delete" onclick="deleteFile('${htmlEscape(file.name)}')">
                                    <i class="fas fa-trash"></i> Hapus
                                </button>
                            `;
                        } else if (isCancelled || isFailed) {
                            // Show Try Again and Delete for cancelled/failed files
                            actionButtons = `
                                <button class="btn btn-retry" onclick="retryPrint('${htmlEscape(file.name)}')">
                                    <i class="fas fa-redo"></i> Ulangi
                                </button>
                                <button class="btn btn-delete" onclick="deleteFile('${htmlEscape(file.name)}')">
                                    <i class="fas fa-trash"></i> Hapus
                                </button>
                            `;
                        } else {
                            // Show Print and Delete for ready files
                            actionButtons = `
                                <button class="btn btn-print" id="print-${htmlEscape(file.name)}" onclick="printFile('${htmlEscape(file.name)}')">
                                    <i class="fas fa-print"></i> Print
                                </button>
                                <button class="btn btn-delete" onclick="deleteFile('${htmlEscape(file.name)}')">
                                    <i class="fas fa-trash"></i> Hapus
                                </button>
                            `;
                        }
                        
                        // Determine status display text
                        let statusText = file.status.toUpperCase();
                        if (isDone) {
                            statusText = '✓ DONE';
                        } else if (isPrinting) {
                            statusText = '⟳ PRINTING';
                        } else if (isCancelled) {
                            statusText = '✕ CANCELLED';
                        } else if (isFailed) {
                            statusText = '⚠ FAILED';
                        } else if (file.status === 'ready') {
                            statusText = '● READY';
                        }
                        
                        // Countdown display for done files
                        let countdownHTML = '';
                        if (isDone) {
                            countdownHTML = `<div class="file-countdown" id="countdown-${htmlEscape(file.name)}">
                                <i class="fas fa-hourglass-end"></i> Akan dihapus dalam 60 detik...
                            </div>`;
                        }
                        
                        return `
                            <div class="file-card" id="file-card-${htmlEscape(file.name)}">
                                <span class="file-icon"><i class="fas fa-file-pdf"></i></span>
                                <div class="file-name" title="${htmlEscape(file.originalName)}">
                                    ${htmlEscape(file.originalName)}
                                </div>
                                <div class="file-size">${formatFileSize(file.size)}</div>
                                <div class="file-status ${file.status}">${statusText}</div>
                                ${countdownHTML}
                                <div class="file-actions">
                                    ${actionButtons}
                                </div>
                            </div>
                        `;
                    }).join('');
                    
                    // Start countdown timers for done files
                    if (data.files && data.files.length > 0) {
                        data.files.forEach(file => {
                            if ((file.status === 'done' || file.status === 'completed') && !countdownTimers[file.name]) {
                                // Fetch status to get countdown value
                                fetch('api.php?action=check_status', {
                                    method: 'POST',
                                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                                    body: 'job_id=' + encodeURIComponent(file.name)
                                })
                                .then(res => res.json())
                                .then(statusData => {
                                    if (statusData.success && statusData.countdown !== undefined && statusData.countdown > 0) {
                                        startCountdownTimer(file.name, statusData.countdown);
                                    }
                                })
                                .catch(err => console.error('Error fetching countdown:', err));
                            }
                        });
                    }
                } else {
                    queueCountSpan.textContent = '0';
                    systemStatusSpan.textContent = 'Ready';
                    fileGrid.innerHTML = `
                        <div class="empty-state" style="grid-column: 1/-1;">
                            <div class="empty-icon"><i class="fas fa-inbox"></i></div>
                            <div>Tidak ada file dalam antrian</div>
                        </div>
                    `;
                }
            })
            .catch(err => {
                console.error('Error:', err);
                fileGrid.innerHTML = `
                    <div class="empty-state" style="grid-column: 1/-1;">
                        <div class="empty-icon"><i class="fas fa-exclamation-triangle"></i></div>
                        <div>Gagal mengambil data file dari server</div>
                    </div>
                `;
            });
    }

    // Update logs
    function updateLogs() {
        fetch('api.php?action=get_logs')
            .then(res => res.json())
            .then(data => {
                if (data.success && data.logs && data.logs.length > 0) {
                    logsContainer.innerHTML = data.logs.map(log => {
                        let className = 'info';
                        let clientColor = 'color-neutral';
                        let clientName = extractClientName(log);
                        
                        if (log.includes('[success]')) className = 'success';
                        else if (log.includes('[error]')) className = 'error';
                        
                        // Get color class based on client name
                        if (clientName) {
                            clientColor = getClientColorClass(clientName);
                        }
                        
                        // Format log entry with colored client name
                        let formattedLog = formatLogEntry(log, clientName);
                        
                        return `<div class="log-entry ${className}" data-client-color="${clientColor}">${formattedLog}</div>`;
                    }).join('');
                    logsContainer.scrollTop = logsContainer.scrollHeight;
                } else {
                    logsContainer.innerHTML = `<div class="log-entry info">Tidak ada log aktivitas hari ini</div>`;
                }
            })
            .catch(err => console.error('Error fetching logs:', err));
    }

    // Print file
    function printFile(filename) {
        // Validasi bahwa user sudah membaca rules
        if (!hasReadRules) {
            alert('⚠️ Anda harus membaca syarat & ketentuan terlebih dahulu sebelum mencetak!\n\nSilakan klik tombol (?) untuk membaca ketentuan.');
            openRulesModal();
            return;
        }

        if (confirm('Cetak file ini?')) {
            // Disable print button immediately
            const printBtn = document.getElementById(`print-${filename}`);
            if (printBtn) {
                printBtn.disabled = true;
                printBtn.style.opacity = '0.5';
                printBtn.style.cursor = 'not-allowed';
            }

            // Track current printing file
            currentPrintingFile = filename;

            // Clear normal intervals when print starts (to avoid multiple overlapping updates)
            if (normalUpdateInterval) clearInterval(normalUpdateInterval);
            if (logsOnlyInterval) clearInterval(logsOnlyInterval);

            fetch('api.php?action=print_file', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'job_id=' + encodeURIComponent(filename) + '&client_name=' + encodeURIComponent(clientName || 'Unknown')
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert('✓ Pencetakan dimulai!');
                    // Update immediately and more frequently while printing
                    updateFileGrid();
                    updateLogs();
                    
                    // Clear old interval
                    if (printStatusCheckInterval) clearInterval(printStatusCheckInterval);
                    
                    // Start checking status more frequently (every 500ms for faster realtime)
                    printStatusCheckInterval = setInterval(() => {
                        fetch('api.php?action=check_status', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                            body: 'job_id=' + encodeURIComponent(filename)
                        })
                        .then(res => res.json())
                        .then(statusData => {
                            if (statusData.success && statusData.completed) {
                                // Print completed!
                                clearInterval(printStatusCheckInterval);
                                printStatusCheckInterval = null;
                                currentPrintingFile = null;
                                
                                // Update UI immediately
                                updateFileGrid();
                                updateLogs();
                                
                                // Start countdown timer if available
                                if (statusData.countdown !== undefined && statusData.countdown > 0) {
                                    setTimeout(() => {
                                        startCountdownTimer(filename, statusData.countdown);
                                    }, 100);
                                }
                                
                                // Restore normal intervals
                                normalUpdateInterval = setInterval(() => {
                                    updateFileGrid();
                                    updateLogs();
                                }, 5000);
                                
                                logsOnlyInterval = setInterval(() => {
                                    updateLogs();
                                }, 3000);
                            } else {
                                // Still printing - keep updating grid
                                updateFileGrid();
                                updateLogs();
                            }
                        });
                    }, 500); // Check every 500ms for faster sync
                } else {
                    alert('✗ ' + (data.message || 'Gagal'));
                    // Re-enable button on error
                    if (printBtn) {
                        printBtn.disabled = false;
                        printBtn.style.opacity = '1';
                        printBtn.style.cursor = 'pointer';
                    }
                    currentPrintingFile = null;
                    
                    // Restore normal intervals on error
                    normalUpdateInterval = setInterval(() => {
                        updateFileGrid();
                        updateLogs();
                    }, 5000);
                    
                    logsOnlyInterval = setInterval(() => {
                        updateLogs();
                    }, 3000);
                }
            })
            .catch(err => {
                console.error('Error:', err);
                alert('❌ Terjadi kesalahan saat mengirim perintah print');
                // Re-enable button on error
                if (printBtn) {
                    printBtn.disabled = false;
                    printBtn.style.opacity = '1';
                    printBtn.style.cursor = 'pointer';
                }
                currentPrintingFile = null;
                
                // Restore normal intervals on error
                normalUpdateInterval = setInterval(() => {
                    updateFileGrid();
                    updateLogs();
                }, 5000);
                
                logsOnlyInterval = setInterval(() => {
                    updateLogs();
                }, 3000);
            });
        }
    }

    // Cancel print
    function cancelPrint(filename) {
        if (confirm('Batalkan pencetakan file ini?')) {
            fetch('api.php?action=cancel_print', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'job_id=' + encodeURIComponent(filename)
            })
            .then(res => res.json())
            .then(data => {
                alert(data.success ? '✓ Pencetakan dibatalkan!' : '✗ ' + (data.message || 'Gagal'));
                
                // Clear interval immediately
                if (printStatusCheckInterval) {
                    clearInterval(printStatusCheckInterval);
                    printStatusCheckInterval = null;
                }
                currentPrintingFile = null;
                
                // Update UI immediately
                updateFileGrid();
                updateLogs();
                
                // Restore normal intervals
                normalUpdateInterval = setInterval(() => {
                    updateFileGrid();
                    updateLogs();
                }, 5000);
                
                logsOnlyInterval = setInterval(() => {
                    updateLogs();
                }, 3000);
            })
            .catch(err => {
                console.error('Error:', err);
                alert('❌ Terjadi kesalahan saat membatalkan print');
            });
        }
    }

    // Delete file
    function deleteFile(filename) {
        if (confirm('Hapus file ini dari antrian?')) {
            // Clear countdown timer if exists
            if (countdownTimers[filename]) {
                clearInterval(countdownTimers[filename]);
                delete countdownTimers[filename];
            }
            
            fetch('api.php?action=delete_file', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'job_id=' + encodeURIComponent(filename)
            })
            .then(res => res.json())
            .then(data => {
                alert(data.success ? '✓ File berhasil dihapus!' : '✗ Gagal menghapus');
                updateFileGrid();
                updateLogs();
            });
        }
    }

    // Retry print (for cancelled/failed files)
    function retryPrint(filename) {
        if (confirm('Ulangi pencetakan file ini?')) {
            fetch('api.php?action=reset_file_status', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'job_id=' + encodeURIComponent(filename)
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert('✓ Siap untuk dicetak ulang');
                    updateFileGrid();
                    updateLogs();
                } else {
                    alert('✗ Gagal mereset file: ' + (data.message || 'Error'));
                }
            })
            .catch(err => {
                console.error('Error:', err);
                alert('❌ Terjadi kesalahan saat mereset file');
            });
        }
    }

    // Upload file
    uploadForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        
        if (!fileInput.files[0]) {
            alert('Pilih file terlebih dahulu!');
            return;
        }

        uploadBtn.disabled = true;
        uploadBtn.innerHTML = '<span class="spinner"></span> Uploading...';

        const formData = new FormData(uploadForm);
        try {
            const res = await fetch(window.location.href, {
                method: 'POST',
                body: formData
            });
            const html = await res.text();

            if (html.includes('success') && html.includes('antrian')) {
                alert('✓ File berhasil di-upload!');
                fileInput.value = '';
                uploadForm.reset();
                document.getElementById('filePreviewBox').style.display = 'none';
                uploadBtn.style.display = 'none';
                updateFileGrid();
                updateLogs();
            } else {
                alert('✗ Gagal upload file. Silakan coba lagi.');
            }
        } catch (err) {
            alert('✗ Error: ' + err.message);
        } finally {
            uploadBtn.disabled = false;
            uploadBtn.innerHTML = '<i class="fas fa-arrow-up"></i> Upload File';
        }
    });

    // Drag and drop
    uploadBox.addEventListener('dragover', (e) => {
        e.preventDefault();
        uploadBox.style.borderColor = 'var(--success-color)';
        uploadBox.style.background = '#ecfdf5';
    });

    uploadBox.addEventListener('dragleave', () => {
        uploadBox.style.borderColor = 'var(--primary-color)';
        uploadBox.style.background = 'var(--light-bg)';
    });

    uploadBox.addEventListener('drop', (e) => {
        e.preventDefault();
        uploadBox.style.borderColor = 'var(--primary-color)';
        uploadBox.style.background = 'var(--light-bg)';
        
        const files = e.dataTransfer.files;
        if (files[0] && files[0].type === 'application/pdf') {
            fileInput.files = files;
            // Trigger change event to show preview
            fileInput.dispatchEvent(new Event('change', { bubbles: true }));
        } else {
            alert('Hanya file PDF yang diperbolehkan!');
        }
    });

    uploadBox.addEventListener('click', () => fileInput.click());

    // File input change - show preview and upload button
    fileInput.addEventListener('change', () => {
        const filePreviewBox = document.getElementById('filePreviewBox');
        const previewFilename = document.getElementById('previewFilename');
        const previewFilesize = document.getElementById('previewFilesize');
        
        if (fileInput.files.length > 0) {
            const file = fileInput.files[0];
            previewFilename.textContent = file.name;
            previewFilesize.textContent = formatFileSize(file.size);
            filePreviewBox.style.display = 'block';
            uploadBtn.style.display = 'inline-block';
        } else {
            filePreviewBox.style.display = 'none';
            uploadBtn.style.display = 'none';
        }
    });

    // Preview view button - open file
    document.getElementById('previewViewBtn').addEventListener('click', (e) => {
        e.preventDefault();
        if (fileInput.files.length > 0) {
            const file = fileInput.files[0];
            const reader = new FileReader();
            reader.onload = (e) => {
                const pdfWindow = window.open();
                pdfWindow.document.write(`<embed src="${e.target.result}" type="application/pdf" width="100%" height="100%">`);
            };
            reader.readAsDataURL(file);
        }
    });

    // Preview delete button - clear selection
    document.getElementById('previewDeleteBtn').addEventListener('click', (e) => {
        e.preventDefault();
        fileInput.value = '';
        document.getElementById('filePreviewBox').style.display = 'none';
        uploadBtn.style.display = 'none';
        uploadBox.style.borderColor = 'var(--primary-color)';
        uploadBox.style.background = 'var(--light-bg)';
    });

    // Update time
    setInterval(() => {
        const now = new Date();
        const timeStr = ('0' + now.getDate()).slice(-2) + '/' +
                        ('0' + (now.getMonth() + 1)).slice(-2) + '/' +
                        now.getFullYear() + ' ' +
                        ('0' + now.getHours()).slice(-2) + ':' +
                        ('0' + now.getMinutes()).slice(-2) + ':' +
                        ('0' + now.getSeconds()).slice(-2);
        currentTimeSpan.textContent = timeStr;
        footerTimeSpan.textContent = timeStr;
    }, 1000);

    // Initialize
    // Check if client is new
    checkClientStatus();
    
    updateFileGrid();
    updateLogs();
    
    // Setup normal update intervals (slower when not printing)
    normalUpdateInterval = setInterval(() => {
        updateFileGrid();
        updateLogs();
    }, 5000); // Every 5 seconds
    
    logsOnlyInterval = setInterval(() => {
        updateLogs();
    }, 3000); // Every 3 seconds for logs
</script>

</body>
</html>
