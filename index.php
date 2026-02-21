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
            
            addLog("File uploaded: {$finalFilename} (Session: " . session_id() . ")", 'success');
            
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
            padding: 6px 0;
            border-bottom: 1px solid #334155;
            line-height: 1.5;
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

        .empty-state {
            text-align: center;
            padding: 40px;
            color: var(--text-light);
        }

        .empty-icon {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.5;
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

    // Modal Functions
    function openRulesModal() {
        rulesModal.classList.add('show');
    }

    function closeRulesModal() {
        rulesModal.classList.remove('show');
    }

    // Close modal when clicking outside of it
    window.addEventListener('click', (event) => {
        if (event.target === rulesModal) {
            closeRulesModal();
        }
    });

    // Close modal with Escape key
    window.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeRulesModal();
        }
    });

    // Info button click event
    infoBtn.addEventListener('click', (e) => {
        e.preventDefault();
        openRulesModal();
    });

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
                        
                        if (file.status === 'printing') {
                            // Show Cancel button only for printing files
                            actionButtons = `
                                <button class="btn btn-cancel" onclick="cancelPrint('${htmlEscape(file.name)}')">
                                    <i class="fas fa-times"></i> Cancel
                                </button>
                            `;
                        } else if (file.status === 'completed' || file.status === 'done') {
                            // Show delete only for completed files
                            actionButtons = `
                                <button class="btn btn-delete" onclick="deleteFile('${htmlEscape(file.name)}')">
                                    <i class="fas fa-trash"></i> Hapus
                                </button>
                            `;
                        } else if (file.status === 'cancelled') {
                            // Show delete only for cancelled files
                            actionButtons = `
                                <button class="btn btn-delete" onclick="deleteFile('${htmlEscape(file.name)}')">
                                    <i class="fas fa-trash"></i> Hapus
                                </button>
                            `;
                        } else {
                            // Show Print and Delete for ready files
                            actionButtons = `
                                <button class="btn btn-print" onclick="printFile('${htmlEscape(file.name)}')">
                                    <i class="fas fa-print"></i> Print
                                </button>
                                <button class="btn btn-delete" onclick="deleteFile('${htmlEscape(file.name)}')">
                                    <i class="fas fa-trash"></i> Hapus
                                </button>
                            `;
                        }
                        
                        // Determine status display text
                        let statusText = file.status.toUpperCase();
                        if (file.status === 'done' || file.status === 'completed') {
                            statusText = '✓ DONE';
                        } else if (file.status === 'printing') {
                            statusText = '⟳ PRINTING';
                        } else if (file.status === 'cancelled') {
                            statusText = '✕ CANCELLED';
                        } else if (file.status === 'ready') {
                            statusText = '● READY';
                        }
                        
                        return `
                            <div class="file-card">
                                <span class="file-icon"><i class="fas fa-file-pdf"></i></span>
                                <div class="file-name" title="${htmlEscape(file.originalName)}">
                                    ${htmlEscape(file.originalName)}
                                </div>
                                <div class="file-size">${formatFileSize(file.size)}</div>
                                <div class="file-status ${file.status}">${statusText}</div>
                                <div class="file-actions">
                                    ${actionButtons}
                                </div>
                            </div>
                        `;
                    }).join('');
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
                        if (log.includes('[success]')) className = 'success';
                        else if (log.includes('[error]')) className = 'error';
                        return `<div class="log-entry ${className}">${htmlEscape(log)}</div>`;
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
        if (confirm('Cetak file ini?')) {
            fetch('api.php?action=print_file', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'job_id=' + encodeURIComponent(filename)
            })
            .then(res => res.json())
            .then(data => {
                alert(data.success ? '✓ Pencetakan dimulai!' : '✗ ' + (data.message || 'Gagal'));
                updateFileGrid();
                updateLogs();
                // Update more frequently while printing
                clearInterval(printCheckInterval);
                printCheckInterval = setInterval(() => {
                    updateFileGrid();
                    updateLogs();
                }, 1000); // Update every 1 second while printing
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
                clearInterval(printCheckInterval);
                printCheckInterval = setInterval(() => {
                    updateFileGrid();
                    updateLogs();
                }, 5000); // Back to normal interval
                updateFileGrid();
                updateLogs();
            });
        }
    }

    // Delete file
    function deleteFile(filename) {
        if (confirm('Hapus file ini dari antrian?')) {
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
    let printCheckInterval; // Global variable for print checking interval
    
    updateFileGrid();
    updateLogs();
    
    // Setup normal update intervals
    printCheckInterval = setInterval(() => {
        updateFileGrid();
        updateLogs();
    }, 5000);
    
    setInterval(updateLogs, 3000);
</script>

</body>
</html>
