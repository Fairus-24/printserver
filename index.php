<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: text/html; charset=UTF-8');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/printer.php';

// Set timezone to Indonesia (Asia/Jakarta)
date_default_timezone_set('Asia/Jakarta');

// Configuration
$printer = detectPrinterName('Deteksi Perangkat');
$uploadsDir = __DIR__ . '/uploads/';
$logsDir = __DIR__ . '/logs/';
$sumatraPdfPath = getSumatraPdfPath();

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
$isAuthenticated = isset($_SESSION['auth_user']) && is_array($_SESSION['auth_user']) && !empty($_SESSION['auth_user']['nim_nipy']);
$authenticatedUser = $isAuthenticated ? $_SESSION['auth_user'] : null;
$isAdmin = $isAuthenticated && (($authenticatedUser['role'] ?? '') === 'admin');

if (!isset($_SESSION['files'])) {
    $_SESSION['files'] = [];
}

// Handle POST upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    if (!$isAuthenticated) {
        $status = 'error';
        $message = 'Anda harus login dengan NIM/NIPY dan password.';
        addLog("Upload ditolak karena user belum login (Session: " . session_id() . ")", 'warning');
    } else {
    $file = $_FILES['file'];
    $filename = basename($file['name']);
    $filesize = $file['size'];
    $tmpFile = $file['tmp_name'];
    $maxSize = 100 * 1024 * 1024;
    
    // Check for renamed filename
    $renamedFilename = $_POST['renamed_filename'] ?? null;
    $hideFilename = isset($_POST['hide_filename']) && $_POST['hide_filename'] === '1';
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $status = 'error';
        $message = 'Kesalahan upload: ' . $file['error'];
        addLog("Upload error: {$filename}", 'error');
    } elseif (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'pdf') {
        $status = 'error';
        $message = 'Hanya file PDF yang diperbolehkan!';
        addLog("Invalid format: {$filename}", 'error');
    } elseif ($filesize > $maxSize) {
        $status = 'error';
        $message = 'Ukuran file melebihi 100MB';
        addLog("File too large: {$filename}", 'error');
    } else {
        // Use renamed filename if provided, otherwise use original
        if (!empty($renamedFilename)) {
            $finalFilename = basename($renamedFilename);
            // Ensure it has .pdf extension
            if (strtolower(pathinfo($finalFilename, PATHINFO_EXTENSION)) !== 'pdf') {
                $finalFilename = pathinfo($finalFilename, PATHINFO_FILENAME) . '.pdf';
            }
        } else {
            $finalFilename = $filename;
        }
        
        $destPath = $uploadsDir . $finalFilename;
        
        // Check for duplicate filename
        $counter = 1;
        $baseFilename = pathinfo($finalFilename, PATHINFO_FILENAME);
        $extension = pathinfo($finalFilename, PATHINFO_EXTENSION);
        $originalFinalFilename = $finalFilename;
        
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
                'owner_session' => session_id(),
                'hide_filename' => $hideFilename
            ];
            
            $clientName = $_SESSION['auth_user']['full_name'] ?? ($_SESSION['client_name'] ?? 'Unknown');
            $displayName = $hideFilename ? '****.pdf' : $finalFilename;
            addLog("File uploaded: {$displayName} | Client: $clientName (Session: " . session_id() . ")", 'success');
            
            $status = 'success';
            $message = 'File berhasil di-upload! Silakan cetak file dari daftar antrian di bawah.';
        } else {
            $status = 'error';
            $message = 'Gagal menyimpan file';
            addLog("Failed to save file: {$filename}", 'error');
        }
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
    <meta name="description" content="FIK Smart Print Server: antrian print PDF realtime multi-user dengan login SQL, queue global, status global, dan log print terpusat.">
    <meta name="theme-color" content="#667eea">
    <meta name="msapplication-TileColor" content="#667eea">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <link rel="icon" type="image/svg+xml" href="assets/brand-icon.svg">
    <link rel="apple-touch-icon" href="assets/brand-icon.svg">
    <meta property="og:type" content="website">
    <meta property="og:title" content="FIK Smart Print Server">
    <meta property="og:description" content="Queue print PDF realtime multi-user dengan status global dan log print yang jelas.">
    <meta property="og:image" content="assets/meta-card.svg">
    <meta property="og:image:type" content="image/svg+xml">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="FIK Smart Print Server">
    <meta name="twitter:description" content="Queue print PDF realtime multi-user dengan status global dan log print yang jelas.">
    <meta name="twitter:image" content="assets/meta-card.svg">
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

        .status-logout-btn {
            background: rgba(255, 255, 255, 0.15);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.35);
            border-radius: 999px;
            padding: 6px 14px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .status-logout-btn:hover {
            background: rgba(255, 255, 255, 0.25);
        }

        .status-link-btn {
            background: rgba(255, 255, 255, 0.15);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.35);
            border-radius: 999px;
            padding: 6px 14px;
            font-size: 12px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .status-link-btn:hover {
            background: rgba(255, 255, 255, 0.25);
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
        .btn-preview-delete,
        .btn-preview-rename {
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

        .btn-preview-rename:hover {
            background: var(--warning-color);
            color: white;
            border-color: var(--warning-color);
        }

        .preview-rename-section {
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid var(--border-color);
        }

        .preview-rename-input {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            font-size: 13px;
            font-family: inherit;
            background: white;
            color: var(--text-dark);
            transition: all 0.2s ease;
        }

        .preview-rename-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(99, 102, 241, 0.1);
        }

        .preview-rename-hint {
            display: block;
            font-size: 11px;
            color: var(--text-light);
            margin-top: 4px;
        }

        .preview-options {
            padding: 12px 0;
            border-top: 1px solid var(--border-color);
            margin-top: 12px;
        }

        .checkbox-label {
            display: flex;
            align-items: center;
            cursor: pointer;
            user-select: none;
            transition: all 0.2s ease;
        }

        .checkbox-label:hover {
            color: var(--primary-color);
        }

        .checkbox-input {
            width: 16px;
            height: 16px;
            margin-right: 10px;
            cursor: pointer;
            accent-color: var(--primary-color);
        }

        .checkbox-text {
            font-size: 13px;
            font-weight: 500;
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
            padding-top: 26px;
            text-align: center;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            position: relative;
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

        .file-print-mode {
            font-size: 11px;
            font-weight: 700;
            margin-bottom: 10px;
            padding: 5px 8px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: 1px solid transparent;
        }

        .file-print-mode.color {
            color: #0f766e;
            background: rgba(16, 185, 129, 0.12);
            border-color: rgba(16, 185, 129, 0.25);
        }

        .file-print-mode.grayscale {
            color: #334155;
            background: rgba(148, 163, 184, 0.16);
            border-color: rgba(148, 163, 184, 0.28);
        }

        .file-queue-pos {
            font-size: 12px;
            font-weight: 700;
            color: #0f766e;
            margin-bottom: 10px;
            background: rgba(16, 185, 129, 0.12);
            border-radius: 8px;
            padding: 6px 8px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
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

        .file-status.uploaded {
            background: #e5e7eb;
            color: #374151;
        }

        .file-status.printing {
            background: #fef3c7;
            color: #92400e;
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

        .file-status.failed {
            background: #fee2e2;
            color: #b91c1c;
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

        .file-error-message {
            margin-top: 8px;
            margin-bottom: 4px;
            padding: 8px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            color: #b91c1c;
            background: rgba(239, 68, 68, 0.1);
            word-break: break-word;
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

        .file-card-preview {
            position: absolute;
            top: 10px;
            right: 10px;
            z-index: 2;
        }

        .btn-preview-file {
            background: #0ea5e9;
            color: white;
            width: 34px;
            height: 34px;
            padding: 0;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 auto;
            text-transform: none;
            letter-spacing: 0;
        }

        .btn-preview-file:hover {
            background: #0284c7;
            box-shadow: 0 4px 12px rgba(14, 165, 233, 0.3);
        }

        .btn-waiting {
            background: #e5e7eb;
            color: #374151;
            cursor: not-allowed;
            box-shadow: none;
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
            scroll-behavior: smooth;
        }

        .logs-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-top: 8px;
            margin-bottom: -8px;
            flex-wrap: wrap;
        }

        .log-filter-group {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .log-filter-btn {
            border: 1px solid #cbd5e1;
            background: #f8fafc;
            color: #334155;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.3px;
            text-transform: uppercase;
            padding: 6px 10px;
            border-radius: 999px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .log-filter-btn:hover {
            background: #e2e8f0;
            border-color: #94a3b8;
        }

        .log-filter-btn.active {
            background: #0f172a;
            color: #e2e8f0;
            border-color: #334155;
            box-shadow: 0 2px 6px rgba(15, 23, 42, 0.25);
        }

        .log-filter-meta {
            font-size: 11px;
            color: #64748b;
            font-weight: 600;
        }

        .log-entry {
            padding: 8px 10px 10px;
            border-bottom: 1px solid #334155;
            line-height: 1.4;
            border-left: 4px solid var(--client-accent, #334155);
            margin-bottom: 2px;
            transition: all 0.2s ease;
            border-radius: 8px;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px;
            background: rgba(15, 23, 42, 0.35);
        }

        .log-entry:hover {
            background-color: rgba(100, 116, 139, 0.3);
        }

        .log-entry:last-child {
            border-bottom: none;
        }

        .log-time {
            color: #cbd5e1;
            font-weight: 700;
            letter-spacing: 0.3px;
            min-width: 64px;
        }

        .log-badge {
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 0.4px;
            padding: 2px 7px;
            border-radius: 999px;
            border: 1px solid #475569;
            background: #0f172a;
            color: #e2e8f0;
            text-transform: uppercase;
        }

        .log-entry.success .log-badge {
            color: #34d399;
            border-color: #10b981;
            background: rgba(5, 150, 105, 0.18);
        }

        .log-entry.info .log-badge {
            color: #60a5fa;
            border-color: #3b82f6;
            background: rgba(37, 99, 235, 0.18);
        }

        .log-entry.warning .log-badge {
            color: #fbbf24;
            border-color: #f59e0b;
            background: rgba(217, 119, 6, 0.2);
        }

        .log-entry.error .log-badge {
            color: #fca5a5;
            border-color: #ef4444;
            background: rgba(220, 38, 38, 0.2);
        }

        .log-entry[data-badge="SENT"] .log-badge {
            color: #67e8f9;
            border-color: #22d3ee;
            background: rgba(8, 145, 178, 0.22);
        }

        .log-entry[data-badge="QUEUE"] .log-badge {
            color: #bfdbfe;
            border-color: #60a5fa;
            background: rgba(30, 64, 175, 0.25);
        }

        .log-entry[data-badge="PRINT"] .log-badge {
            color: #fcd34d;
            border-color: #f59e0b;
            background: rgba(180, 83, 9, 0.26);
        }

        .log-entry[data-badge="DONE"] .log-badge {
            color: #6ee7b7;
            border-color: #34d399;
            background: rgba(5, 150, 105, 0.25);
        }

        .log-entry.success .log-message {
            color: #d1fae5;
        }

        .log-entry.info .log-message {
            color: #dbeafe;
        }

        .log-entry.warning .log-message {
            color: #fef3c7;
        }

        .log-entry.error .log-message {
            color: #fee2e2;
        }

        .log-client {
            font-weight: 800;
            padding: 2px 8px;
            border-radius: 999px;
            border: 1px solid var(--client-accent, #64748b);
            background: var(--client-soft, rgba(148, 163, 184, 0.18));
            color: var(--client-accent, #cbd5e1);
        }

        .log-message {
            flex: 1 1 100%;
            color: #dbeafe;
            word-break: break-word;
            padding-left: 2px;
            margin-top: 2px;
        }

        .log-entry[data-client-color="color-neutral"] {
            --client-accent: #94a3b8;
            --client-soft: rgba(148, 163, 184, 0.15);
        }
        .log-entry[data-client-color="color1"] {
            --client-accent: #f97316;
            --client-soft: rgba(249, 115, 22, 0.2);
        }
        .log-entry[data-client-color="color2"] {
            --client-accent: #22d3ee;
            --client-soft: rgba(34, 211, 238, 0.2);
        }
        .log-entry[data-client-color="color3"] {
            --client-accent: #facc15;
            --client-soft: rgba(250, 204, 21, 0.2);
        }
        .log-entry[data-client-color="color4"] {
            --client-accent: #4ade80;
            --client-soft: rgba(74, 222, 128, 0.2);
        }
        .log-entry[data-client-color="color5"] {
            --client-accent: #a78bfa;
            --client-soft: rgba(167, 139, 250, 0.2);
        }
        .log-entry[data-client-color="color6"] {
            --client-accent: #f472b6;
            --client-soft: rgba(244, 114, 182, 0.2);
        }
        .log-entry[data-client-color="color7"] {
            --client-accent: #2dd4bf;
            --client-soft: rgba(45, 212, 191, 0.2);
        }
        .log-entry[data-client-color="color8"] {
            --client-accent: #fb7185;
            --client-soft: rgba(251, 113, 133, 0.2);
        }
        .log-entry[data-client-color="color9"] {
            --client-accent: #818cf8;
            --client-soft: rgba(129, 140, 248, 0.2);
        }
        .log-entry[data-client-color="color10"] {
            --client-accent: #f43f5e;
            --client-soft: rgba(244, 63, 94, 0.2);
        }
        .log-entry[data-client-color="color11"] {
            --client-accent: #60a5fa;
            --client-soft: rgba(96, 165, 250, 0.2);
        }
        .log-entry[data-client-color="color12"] {
            --client-accent: #34d399;
            --client-soft: rgba(52, 211, 153, 0.2);
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

        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1400;
            display: flex;
            flex-direction: column;
            gap: 10px;
            width: min(92vw, 360px);
        }

        .toast {
            display: flex;
            align-items: center;
            gap: 10px;
            border-radius: 10px;
            padding: 10px 12px;
            font-size: 13px;
            font-weight: 600;
            box-shadow: 0 10px 26px rgba(15, 23, 42, 0.22);
            transform: translateX(18px);
            opacity: 0;
            transition: transform 0.2s ease, opacity 0.2s ease;
        }

        .toast.show {
            transform: translateX(0);
            opacity: 1;
        }

        .toast.info {
            background: #dbeafe;
            color: #1e3a8a;
            border: 1px solid #93c5fd;
        }

        .toast.success {
            background: #dcfce7;
            color: #14532d;
            border: 1px solid #86efac;
        }

        .toast.warning {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fcd34d;
        }

        .toast.error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }

        .toast-icon {
            width: 18px;
            text-align: center;
            flex-shrink: 0;
        }

        .toast-message {
            flex: 1;
            line-height: 1.4;
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
            content: "*";
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

        /* Professional Confirm Modal */
        .confirm-modal {
            position: fixed;
            inset: 0;
            z-index: 1300;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 16px;
            background: rgba(15, 23, 42, 0.55);
            backdrop-filter: blur(2px);
        }

        .confirm-modal.show {
            display: flex;
        }

        .confirm-box {
            width: 100%;
            max-width: 430px;
            background: #fff;
            border-radius: 14px;
            border: 1px solid var(--border-color);
            box-shadow: 0 25px 50px rgba(2, 6, 23, 0.28);
            padding: 18px;
        }

        .confirm-icon {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            margin-bottom: 10px;
        }

        .confirm-icon.primary {
            background: #ccfbf1;
            color: #0f766e;
        }

        .confirm-icon.warning {
            background: #fef3c7;
            color: #b45309;
        }

        .confirm-icon.danger {
            background: #fee2e2;
            color: #b91c1c;
        }

        .confirm-title {
            font-size: 18px;
            font-weight: 800;
            margin-bottom: 6px;
            color: #0f172a;
        }

        .confirm-message {
            font-size: 14px;
            color: #475569;
            line-height: 1.55;
            margin-bottom: 16px;
        }

        .print-mode-options {
            margin-top: 12px;
            display: grid;
            gap: 8px;
        }

        .print-mode-option {
            display: flex;
            align-items: center;
            gap: 10px;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            padding: 10px 12px;
            background: #f8fafc;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .print-mode-option:hover {
            border-color: #94a3b8;
            background: #f1f5f9;
        }

        .print-mode-option input[type="radio"] {
            accent-color: #0f766e;
        }

        .print-mode-title {
            font-size: 13px;
            font-weight: 700;
            color: #0f172a;
        }

        .print-mode-desc {
            font-size: 12px;
            color: #475569;
        }

        .confirm-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            flex-wrap: wrap;
        }

        .confirm-btn {
            border: none;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            padding: 9px 14px;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .confirm-btn:hover {
            transform: translateY(-1px);
        }

        .confirm-btn.secondary {
            background: #e2e8f0;
            color: #334155;
        }

        .confirm-btn.primary {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: #fff;
        }

        .confirm-btn.warning {
            background: #f59e0b;
            color: #fff;
        }

        .confirm-btn.danger {
            background: #ef4444;
            color: #fff;
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
                <div class="status-indicator">
                    <strong>User:</strong> <span id="currentUser"><?php echo htmlspecialchars($authenticatedUser['full_name'] ?? '-'); ?></span>
                </div>
                <div class="status-indicator">
                    <button type="button" id="logoutBtn" class="status-logout-btn">Logout</button>
                </div>
                <div class="status-indicator">
                    <a href="users.php" id="manageUsersBtn" class="status-link-btn" style="<?php echo $isAdmin ? '' : 'display: none;'; ?>">
                        <i class="fas fa-users-cog"></i> Kelola User
                    </a>
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
                            <!-- Rename input -->
                            <div class="preview-rename-section" id="previewRenameSection" style="display: none;">
                                <input type="text" id="previewRenameInput" class="preview-rename-input" placeholder="Nama file baru...">
                                <small class="preview-rename-hint">.pdf akan ditambahkan otomatis</small>
                            </div>
                        </div>
                        <div class="preview-actions">
                            <button type="button" class="btn-preview-rename" id="previewRenameBtn" title="Ubah nama file">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button type="button" class="btn-preview-view" id="previewViewBtn" title="Lihat File">
                                <i class="fas fa-eye"></i>
                            </button>
                            <button type="button" class="btn-preview-delete" id="previewDeleteBtn" title="Hapus Pilihan">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                    <!-- Hide filename checkbox -->
                    <div class="preview-options">
                        <label class="checkbox-label">
                            <input type="checkbox" id="hideFilenameCB" class="checkbox-input">
                            <span class="checkbox-text">Sembunyikan nama di Log Global</span>
                        </label>
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
            <div class="logs-toolbar">
                <div class="log-filter-group" id="logFilterGroup">
                    <button type="button" class="log-filter-btn active" data-filter="all">Semua</button>
                    <button type="button" class="log-filter-btn" data-filter="queue">Queue</button>
                    <button type="button" class="log-filter-btn" data-filter="sent">Sent</button>
                    <button type="button" class="log-filter-btn" data-filter="error">Error</button>
                </div>
                <div class="log-filter-meta" id="logFilterMeta">Filter: Semua</div>
            </div>
            <div class="logs-container" id="logsContainer">
                <div class="log-entry info" data-client-color="color-neutral">
                    <span class="log-time">--:--:--</span>
                    <span class="log-badge">INFO</span>
                    <span class="log-client">System</span>
                    <span class="log-message">Memuat log aktivitas...</span>
                </div>
            </div>
        </div>

        <!-- Login Modal -->
        <div class="modal" id="nameModal">
            <div class="modal-content modal-sm">
                <div class="modal-header">
                    <h2><i class="fas fa-lock"></i> Login Pengguna</h2>
                </div>
                <div class="modal-body">
                    <p style="margin-bottom: 20px; text-align: center; color: var(--text-light);">
                        Masukkan NIM/NIPY dan password untuk menggunakan sistem print server.
                    </p>
                    <p style="margin-bottom: 15px; text-align: center; color: var(--text-light); font-size: 12px;">
                        Akun dummy: <strong>23123456</strong>, <strong>1987654321</strong> (password <strong>dummy12345</strong>) | Admin: <strong>19770001</strong> (password <strong>admin12345</strong>)
                    </p>
                    <form id="loginForm">
                        <input 
                            type="text" 
                            id="nimNipy" 
                            class="input-field" 
                            placeholder="Masukkan NIM/NIPY..." 
                            required 
                            autocomplete="off"
                        >
                        <input 
                            type="password" 
                            id="loginPassword" 
                            class="input-field" 
                            placeholder="Masukkan password..." 
                            required 
                            autocomplete="current-password"
                        >
                        <div id="loginError" style="display: none; margin-bottom: 15px; color: #b91c1c; font-size: 13px; text-align: center;"></div>
                        <button type="submit" class="btn btn-primary btn-full">
                            <i class="fas fa-sign-in-alt"></i> Login
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
                        <p>Klik tombol "Upload File" untuk mengirim file ke server. File akan berstatus "UPLOADED" dan belum masuk antrian print.</p>
                    </div>

                    <div class="tour-step">
                        <div class="tour-step-number">4</div>
                        <h3><i class="fas fa-print"></i> Cetak File</h3>
                        <p>Klik tombol "Print" pada file berstatus "UPLOADED" agar file masuk ke antrian global. Status berubah menjadi "QUEUED #nomor".</p>
                    </div>

                    <div class="tour-step">
                        <div class="tour-step-number">5</div>
                        <h3><i class="fas fa-spinner"></i> Monitor Status</h3>
                        <p>Saat diproses, status berubah menjadi "PRINTING... #nomor". Jika terjadi masalah, status akan berubah menjadi "ERROR" dengan detail pesan.</p>
                    </div>

                    <div class="tour-step">
                        <div class="tour-step-number">6</div>
                        <h3><i class="fas fa-check-circle"></i> Selesai</h3>
                        <p>Setelah selesai, status berubah menjadi "DONE". File akan otomatis dihapus dari antrian setelah 60 detik. Ambil hasil cetakan dari printer.</p>
                    </div>

                    <div class="tour-step info-box" style="margin-top: 20px;">
                        <i class="fas fa-lightbulb"></i>
                        <p><strong>Tips Penting:</strong> <br>
                        - Periksa kembali ketentuan sebelum mencetak<br>
                        - Gunakan file PDF dengan resolusi 300 DPI untuk hasil terbaik<br>
                        - Jangan menutup halaman saat proses pencetakan</p>
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

        <div id="confirmModal" class="confirm-modal" aria-hidden="true" role="dialog" aria-modal="true">
            <div class="confirm-box">
                <div id="confirmIcon" class="confirm-icon primary"><i class="fas fa-circle-question"></i></div>
                <div id="confirmTitle" class="confirm-title">Konfirmasi</div>
                <div id="confirmMessage" class="confirm-message">Apakah Anda yakin ingin melanjutkan?</div>
                <div class="confirm-actions">
                    <button type="button" id="confirmCancelBtn" class="confirm-btn secondary">
                        <i class="fas fa-times"></i> Batal
                    </button>
                    <button type="button" id="confirmOkBtn" class="confirm-btn primary">
                        <i class="fas fa-check"></i> Lanjutkan
                    </button>
                </div>
            </div>
        </div>

        <div id="toastContainer" class="toast-container" aria-live="polite" aria-atomic="true"></div>

        <!-- Footer -->
        <footer>
            <i class="fas fa-server"></i> FIK Print Server | Printer: <strong><?php echo htmlspecialchars($printer); ?></strong> | 
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
    const logFilterButtons = Array.from(document.querySelectorAll('.log-filter-btn'));
    const logFilterMeta = document.getElementById('logFilterMeta');
    const queueCountSpan = document.getElementById('queueCount');
    const systemStatusSpan = document.getElementById('systemStatus');
    const currentTimeSpan = document.getElementById('currentTime');
    const footerTimeSpan = document.getElementById('footerTime');
    const rulesModal = document.getElementById('rulesModal');
    const infoBtn = document.getElementById('infoBtn');
    const nameModal = document.getElementById('nameModal');
    const tourModal = document.getElementById('tourModal');
    const loginForm = document.getElementById('loginForm');
    const nimNipyInput = document.getElementById('nimNipy');
    const loginPasswordInput = document.getElementById('loginPassword');
    const loginError = document.getElementById('loginError');
    const currentUserSpan = document.getElementById('currentUser');
    const logoutBtn = document.getElementById('logoutBtn');
    const manageUsersBtn = document.getElementById('manageUsersBtn');
    const confirmModal = document.getElementById('confirmModal');
    const confirmTitle = document.getElementById('confirmTitle');
    const confirmMessage = document.getElementById('confirmMessage');
    const confirmIcon = document.getElementById('confirmIcon');
    const confirmOkBtn = document.getElementById('confirmOkBtn');
    const confirmCancelBtn = document.getElementById('confirmCancelBtn');
    const toastContainer = document.getElementById('toastContainer');

    // Auth user tracking
    let clientName = <?php echo json_encode($authenticatedUser['full_name'] ?? ''); ?>;
    let hasReadRules = localStorage.getItem('hasReadRules') === 'true';
    let hasSeenGuide = localStorage.getItem('hasSeenGuide') === 'true';
    let normalUpdateInterval = null;
    let logsOnlyInterval = null;
    let confirmResolver = null;
    let isFetchingFiles = false;
    let isFetchingLogs = false;
    let previewObjectUrl = null;
    let logsAutoScrollEnabled = true;
    let isProgrammaticLogScroll = false;
    let logsIdleResumeTimer = null;
    let lastLogInteractionMark = 0;
    let activeLogFilter = 'all';
    let cachedLogItems = [];
    let preferredPrintMode = localStorage.getItem('preferredPrintMode') === 'grayscale' ? 'grayscale' : 'color';
    const LOG_IDLE_RESUME_MS = 5000;
    const clientColorAssignments = new Map();
    const clientColorPool = [
        'color1', 'color2', 'color3', 'color4', 'color5', 'color6',
        'color7', 'color8', 'color9', 'color10', 'color11', 'color12'
    ];

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
        clearLoginError();
        setTimeout(() => {
            nimNipyInput.focus();
        }, 100);
    }

    function closeNameModal() {
        nameModal.classList.remove('show');
    }

    function showToast(message, type = 'info', durationMs = 3200) {
        if (!toastContainer) {
            return;
        }

        const level = ['info', 'success', 'warning', 'error'].includes(type) ? type : 'info';
        const iconMap = {
            info: 'fa-circle-info',
            success: 'fa-circle-check',
            warning: 'fa-triangle-exclamation',
            error: 'fa-circle-xmark'
        };

        const toast = document.createElement('div');
        toast.className = `toast ${level}`;
        toast.innerHTML = `
            <span class="toast-icon"><i class="fas ${iconMap[level]}"></i></span>
            <span class="toast-message"></span>
        `;
        const msg = toast.querySelector('.toast-message');
        if (msg) {
            msg.textContent = String(message || '');
        }

        toastContainer.appendChild(toast);
        requestAnimationFrame(() => {
            toast.classList.add('show');
        });

        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => {
                if (toast.parentNode) {
                    toast.parentNode.removeChild(toast);
                }
            }, 200);
        }, Math.max(1500, durationMs));
    }

    function setConfirmTone(tone) {
        confirmOkBtn.classList.remove('primary', 'warning', 'danger');
        confirmIcon.classList.remove('primary', 'warning', 'danger');

        if (tone === 'danger') {
            confirmOkBtn.classList.add('danger');
            confirmIcon.classList.add('danger');
            confirmIcon.innerHTML = '<i class="fas fa-trash"></i>';
            return;
        }

        if (tone === 'warning') {
            confirmOkBtn.classList.add('warning');
            confirmIcon.classList.add('warning');
            confirmIcon.innerHTML = '<i class="fas fa-triangle-exclamation"></i>';
            return;
        }

        confirmOkBtn.classList.add('primary');
        confirmIcon.classList.add('primary');
        confirmIcon.innerHTML = '<i class="fas fa-circle-question"></i>';
    }

    function closeConfirm(result) {
        confirmModal.classList.remove('show');
        confirmModal.setAttribute('aria-hidden', 'true');
        if (confirmResolver) {
            const resolver = confirmResolver;
            confirmResolver = null;
            resolver(result);
        }
    }

    function openConfirm(options) {
        const tone = options?.tone || 'primary';
        const title = options?.title || 'Konfirmasi';
        const message = options?.message || 'Apakah Anda yakin ingin melanjutkan?';
        const okText = options?.okText || 'Lanjutkan';
        const cancelText = options?.cancelText || 'Batal';

        setConfirmTone(tone);
        confirmTitle.textContent = title;
        confirmMessage.textContent = message;
        confirmOkBtn.innerHTML = `<i class="fas fa-check"></i> ${htmlEscape(okText)}`;
        confirmCancelBtn.innerHTML = `<i class="fas fa-times"></i> ${htmlEscape(cancelText)}`;

        confirmModal.classList.add('show');
        confirmModal.setAttribute('aria-hidden', 'false');

        setTimeout(() => {
            confirmOkBtn.focus();
        }, 0);

        return new Promise((resolve) => {
            confirmResolver = resolve;
        });
    }

    function normalizeClientPrintMode(mode) {
        const normalized = String(mode || '').toLowerCase().trim();
        return normalized === 'grayscale' ? 'grayscale' : 'color';
    }

    function getPrintModeLabel(mode) {
        return normalizeClientPrintMode(mode) === 'grayscale' ? 'Hitam Putih' : 'Berwarna';
    }

    function openPrintModeConfirm(filename, suggestedMode = '') {
        const selectedFilename = String(filename || '').trim() || 'file.pdf';
        const defaultMode = normalizeClientPrintMode(suggestedMode || preferredPrintMode);
        const checkedColor = defaultMode === 'color' ? 'checked' : '';
        const checkedGray = defaultMode === 'grayscale' ? 'checked' : '';

        setConfirmTone('primary');
        confirmTitle.textContent = 'Konfirmasi Cetak';
        confirmMessage.innerHTML = `
            Cetak file "<strong>${htmlEscape(selectedFilename)}</strong>" dengan mode:
            <div class="print-mode-options">
                <label class="print-mode-option">
                    <input type="radio" name="printModeChoice" value="color" ${checkedColor}>
                    <span>
                        <span class="print-mode-title">Berwarna</span><br>
                        <span class="print-mode-desc">Gunakan mode warna asli dokumen</span>
                    </span>
                </label>
                <label class="print-mode-option">
                    <input type="radio" name="printModeChoice" value="grayscale" ${checkedGray}>
                    <span>
                        <span class="print-mode-title">Hitam Putih</span><br>
                        <span class="print-mode-desc">Cetak monokrom untuk hemat tinta</span>
                    </span>
                </label>
            </div>
        `;
        confirmOkBtn.innerHTML = '<i class="fas fa-check"></i> Ya, Cetak';
        confirmCancelBtn.innerHTML = '<i class="fas fa-times"></i> Batal';

        confirmModal.classList.add('show');
        confirmModal.setAttribute('aria-hidden', 'false');

        setTimeout(() => {
            confirmOkBtn.focus();
        }, 0);

        return new Promise((resolve) => {
            confirmResolver = (confirmed) => {
                if (!confirmed) {
                    resolve({ confirmed: false, mode: null });
                    return;
                }

                const selected = document.querySelector('input[name="printModeChoice"]:checked');
                const mode = normalizeClientPrintMode(selected ? selected.value : defaultMode);
                preferredPrintMode = mode;
                localStorage.setItem('preferredPrintMode', mode);

                resolve({ confirmed: true, mode });
            };
        });
    }

    confirmOkBtn.addEventListener('click', () => closeConfirm(true));
    confirmCancelBtn.addEventListener('click', () => closeConfirm(false));
    confirmModal.addEventListener('click', (event) => {
        if (event.target === confirmModal) {
            closeConfirm(false);
        }
    });

    window.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && confirmModal.classList.contains('show')) {
            closeConfirm(false);
        }
    });

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

    function showLoginError(message) {
        loginError.textContent = message;
        loginError.style.display = 'block';
    }

    function clearLoginError() {
        loginError.textContent = '';
        loginError.style.display = 'none';
    }

    function setUserLabel(user) {
        if (!user) {
            currentUserSpan.textContent = '-';
            clientName = '';
            if (manageUsersBtn) {
                manageUsersBtn.style.display = 'none';
            }
            return;
        }

        currentUserSpan.textContent = `${user.full_name} (${user.nim_nipy})`;
        clientName = user.full_name;
        if (manageUsersBtn) {
            manageUsersBtn.style.display = user.role === 'admin' ? 'inline-flex' : 'none';
        }
    }

    // Login form submission
    loginForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        clearLoginError();

        const nimNipy = nimNipyInput.value.trim();
        const password = loginPasswordInput.value;

        if (!nimNipy || !password) {
            showLoginError('NIM/NIPY dan password wajib diisi.');
            return;
        }

        const submitButton = loginForm.querySelector('button[type="submit"]');
        submitButton.disabled = true;

        try {
            const response = await fetch('api.php?action=login', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'nim_nipy=' + encodeURIComponent(nimNipy) + '&password=' + encodeURIComponent(password)
            });

            const data = await response.json();
            if (!data.success) {
                showLoginError(data.message || 'Login gagal');
                return;
            }

            setUserLabel(data.user);
            closeNameModal();
            loginPasswordInput.value = '';

            // Show tour modal on first login per browser
            if (!hasSeenGuide) {
                setTimeout(() => {
                    openTourModal();
                }, 300);
            }

            updateFileGrid();
            updateLogs();
            startRealtimeUpdates();
        } catch (err) {
            console.error('Error login:', err);
            showLoginError('Tidak bisa terhubung ke server login.');
        } finally {
            submitButton.disabled = false;
        }
    });

    // Info button click event
    infoBtn.addEventListener('click', (e) => {
        e.preventDefault();
        openRulesModal();
    });

    async function checkClientStatus() {
        try {
            const response = await fetch('api.php?action=auth_status');
            const data = await response.json();
            if (data.success && data.logged_in && data.user) {
                setUserLabel(data.user);
                closeNameModal();
                return true;
            }
        } catch (err) {
            console.error('Error checking auth status:', err);
        }

        setUserLabel(null);
        openNameModal();
        return false;
    }

    function stopRealtimeUpdates() {
        if (normalUpdateInterval) {
            clearInterval(normalUpdateInterval);
            normalUpdateInterval = null;
        }
        if (logsOnlyInterval) {
            clearInterval(logsOnlyInterval);
            logsOnlyInterval = null;
        }
        if (logsIdleResumeTimer) {
            clearTimeout(logsIdleResumeTimer);
            logsIdleResumeTimer = null;
        }
    }

    function startRealtimeUpdates() {
        stopRealtimeUpdates();
        updateFileGrid(true);
        updateLogs(true);

        // Queue + header status should feel realtime
        normalUpdateInterval = setInterval(() => {
            updateFileGrid();
        }, 1000);

        // Logs can be slightly slower than queue updates
        logsOnlyInterval = setInterval(() => {
            updateLogs();
        }, 1500);
    }

    function applyLoggedOutState() {
        stopRealtimeUpdates();
        isFetchingFiles = false;
        isFetchingLogs = false;
        logsAutoScrollEnabled = true;
        isProgrammaticLogScroll = false;
        cachedLogItems = [];
        activeLogFilter = 'all';

        setUserLabel(null);
        clearLoginError();
        nimNipyInput.value = '';
        loginPasswordInput.value = '';
        queueCountSpan.textContent = '0';
        systemStatusSpan.textContent = 'Ready';
        fileGrid.innerHTML = `
            <div class="empty-state" style="grid-column: 1/-1;">
                <div class="empty-icon"><i class="fas fa-user-lock"></i></div>
                <div>Silakan login untuk melihat antrian file</div>
            </div>
        `;
        updateLogFilterUi(0, 0);
        logsContainer.innerHTML = renderLogPlaceholder('Silakan login untuk melihat log aktivitas', 'info');
        openNameModal();
    }

    async function logoutUser() {
        const shouldLogout = await openConfirm({
            tone: 'warning',
            title: 'Konfirmasi Logout',
            message: 'Logout dari sistem sekarang?',
            okText: 'Ya, Logout',
            cancelText: 'Batal'
        });
        if (!shouldLogout) {
            return;
        }

        try {
            const response = await fetch('api.php?action=logout', {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {'X-Requested-With': 'XMLHttpRequest'}
            });
            const text = await response.text();
            let data = null;
            try {
                data = JSON.parse(text);
            } catch (parseError) {
                data = null;
            }

            applyLoggedOutState();
            if (!data || !data.success) {
                showToast('Logout lokal berhasil. Sinkronisasi server akan dilakukan saat login ulang.', 'warning');
                return;
            }
            showToast('Logout berhasil.', 'success');
        } catch (err) {
            console.error('Error logout:', err);
            applyLoggedOutState();
            showToast('Logout lokal dijalankan karena koneksi server bermasalah.', 'warning');
        }
    }

    logoutBtn.addEventListener('click', logoutUser);

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

    function escapeJsSingleQuote(text) {
        return String(text ?? '')
            .replace(/\\/g, '\\\\')
            .replace(/'/g, "\\'")
            .replace(/\r/g, '\\r')
            .replace(/\n/g, '\\n');
    }

    function stripClientIdentity(clientName) {
        return String(clientName ?? '')
            .replace(/\s*\([^)]*\)\s*$/, '')
            .trim();
    }

    function normalizeClientKey(clientName) {
        return stripClientIdentity(clientName).toLowerCase();
    }

    function getClientColorClass(clientName) {
        const normalized = normalizeClientKey(clientName);
        if (!normalized || normalized === 'unknown' || normalized === 'system') {
            return 'color-neutral';
        }

        if (!clientColorAssignments.has(normalized)) {
            const colorClass = clientColorPool[clientColorAssignments.size % clientColorPool.length];
            clientColorAssignments.set(normalized, colorClass);
        }
        return clientColorAssignments.get(normalized) || 'color-neutral';
    }

    function extractClientName(logEntry) {
        const clientMatch = String(logEntry ?? '').match(/Client:\s*([^|]+?)(?:\s*$|\s*\|)/i);
        if (clientMatch) {
            return stripClientIdentity(clientMatch[1]);
        }
        return null;
    }

    function compactText(value, maxLength = 170) {
        const normalized = String(value ?? '').replace(/\s+/g, ' ').trim();
        if (normalized.length <= maxLength) {
            return normalized;
        }
        return normalized.slice(0, Math.max(0, maxLength - 3)) + '...';
    }

    function parseLogEnvelope(logEntry) {
        const raw = String(logEntry ?? '').trim();
        const match = raw.match(/^\[([^\]]+)\]\s*\[([^\]]+)\]\s*(.*)$/);
        if (!match) {
            return {
                timestamp: '',
                level: 'info',
                message: raw
            };
        }
        return {
            timestamp: (match[1] || '').trim(),
            level: (match[2] || 'info').trim().toLowerCase(),
            message: (match[3] || '').trim()
        };
    }

    function shortTimeLabel(timestamp) {
        const text = String(timestamp ?? '').trim();
        if (text === '') {
            return '--:--:--';
        }
        const pieces = text.split(' ');
        if (pieces.length >= 2) {
            return pieces[pieces.length - 1];
        }
        return text;
    }

    function parseLogTimestampMs(timestamp) {
        const raw = String(timestamp ?? '').trim();
        if (raw === '') {
            return 0;
        }
        const normalized = raw.includes('T') ? raw : raw.replace(' ', 'T');
        const parsed = Date.parse(normalized);
        return Number.isFinite(parsed) ? parsed : 0;
    }

    function getLogBadgePriority(badge) {
        const key = String(badge ?? '').toUpperCase();
        if (key === 'SENT') return 0;
        if (key === 'QUEUE') return 1;
        if (key === 'PRINT' || key === 'START') return 2;
        if (key === 'DONE') return 3;
        if (key === 'CANCEL') return 4;
        if (key === 'ERROR' || key === 'DENY') return 5;
        return 6;
    }

    function normalizeLogModeLabel(modeText) {
        const raw = String(modeText ?? '').trim();
        if (raw === '') {
            return '';
        }
        const key = raw.toLowerCase();
        if (
            key.includes('gray') ||
            key.includes('greyscale') ||
            key.includes('grayscale') ||
            key.includes('mono') ||
            key.includes('hitam')
        ) {
            return 'Grayscale';
        }
        return 'Color';
    }

    function normalizeLogResultLabel(resultText) {
        const raw = String(resultText ?? '').trim();
        if (raw === '') {
            return 'Berhasil';
        }
        const key = raw.toLowerCase();
        if (key.includes('success') || key.includes('berhasil') || key.includes('ok')) {
            return 'Berhasil';
        }
        return compactText(raw, 40);
    }

    function parsePrintLogMessage(message) {
        const cleanedMessage = compactText(
            String(message ?? '').replace(/\s*\|\s*Output:\s*.*$/i, ''),
            500
        );

        const parts = cleanedMessage
            .split('|')
            .map(part => part.trim())
            .filter(part => part !== '');

        const firstPart = parts.shift() || cleanedMessage;
        let client = null;
        let printer = null;
        let queueNumber = null;
        let errorText = null;
        let modeText = null;
        let statusText = null;
        let resultText = null;
        const extras = [];

        for (const part of parts) {
            let match = part.match(/^Client:\s*(.+)$/i);
            if (match) {
                client = stripClientIdentity(match[1]);
                continue;
            }

            match = part.match(/^Printer:\s*(.+)$/i);
            if (match) {
                printer = match[1].trim();
                continue;
            }

            match = part.match(/^Queue(?:\s*No\.?)?\s*:\s*#?\s*(\d+)$/i);
            if (match) {
                queueNumber = match[1];
                continue;
            }

            match = part.match(/^Queue\s*#\s*(\d+)$/i);
            if (match) {
                queueNumber = match[1];
                continue;
            }

            match = part.match(/^Error:\s*(.+)$/i);
            if (match) {
                errorText = compactText(match[1], 110);
                continue;
            }

            match = part.match(/^Mode:\s*(.+)$/i);
            if (match) {
                modeText = compactText(match[1], 40);
                continue;
            }

            match = part.match(/^Status:\s*(.+)$/i);
            if (match) {
                statusText = compactText(match[1], 80);
                continue;
            }

            match = part.match(/^Result:\s*(.+)$/i);
            if (match) {
                resultText = compactText(match[1], 80);
                continue;
            }

            extras.push(compactText(part, 70));
        }

        const details = [];
        if (printer) {
            details.push(`Printer: ${printer}`);
        }
        if (modeText) {
            details.push(`Mode: ${modeText}`);
        }
        if (statusText) {
            details.push(statusText);
        }
        if (resultText) {
            details.push(`Hasil: ${resultText}`);
        }
        if (errorText) {
            details.push(`Error: ${errorText}`);
        }
        if (extras.length > 0) {
            details.push(...extras);
        }

        const withDetails = (baseText) => {
            const compactBase = compactText(baseText, 150);
            if (!details.length) {
                return compactBase;
            }
            return compactText(`${compactBase} | ${details.join(' | ')}`, 220);
        };

        let badge = 'PRINT';
        let tone = 'info';
        let summary = withDetails(firstPart);
        let match = null;

        match = firstPart.match(/^Queue enqueue:\s*(.+)$/i);
        if (match) {
            badge = 'QUEUE';
            tone = 'info';
            const filenameText = compactText(match[1].trim(), 130);
            const queueLabel = queueNumber ? `#${queueNumber}` : '#-';
            summary = compactText(`${filenameText} | Masuk Antrian : ${queueLabel}`, 220);
        }

        match = firstPart.match(/^Queue retry:\s*(.+)$/i);
        if (match) {
            badge = 'QUEUE';
            tone = 'info';
            const filenameText = compactText(match[1].trim(), 130);
            const queueLabel = queueNumber ? `#${queueNumber}` : '#-';
            summary = compactText(`${filenameText} | Masuk Antrian : ${queueLabel}`, 220);
        }

        match = firstPart.match(/^Print started:\s*(.+)$/i);
        if (match) {
            badge = 'PRINT';
            tone = 'info';
            const filenameText = compactText(match[1].trim(), 130);
            let printSummary = `${filenameText} sedang dalam proses print...`;
            if (statusText) {
                const cleanStatus = statusText.replace(/^sedang dalam proses print\.\.\.\s*/i, '').trim();
                if (cleanStatus !== '') {
                    printSummary = `${printSummary} | ${cleanStatus}`;
                }
            }
            if (extras.length > 0) {
                printSummary = `${printSummary} | ${extras.join(' | ')}`;
            }
            summary = compactText(printSummary, 220);
        }

        match = firstPart.match(/^Print sent:\s*(.+)$/i);
        if (match) {
            badge = 'SENT';
            tone = 'success';
            const filenameText = compactText(match[1].trim(), 130);
            const modeLabel = normalizeLogModeLabel(modeText);
            summary = compactText(
                modeLabel !== ''
                    ? `Terkirim: ${filenameText} | Mode : ${modeLabel}`
                    : `Terkirim: ${filenameText}`,
                220
            );
        }

        match = firstPart.match(/^Print done:\s*(.+)$/i);
        if (match) {
            badge = 'DONE';
            tone = 'success';
            const filenameText = compactText(match[1].trim(), 130);
            const resultLabel = normalizeLogResultLabel(resultText);
            const doneParts = [`Telah ${resultLabel} di Print`];
            const modeLabel = normalizeLogModeLabel(modeText);
            if (modeLabel !== '') {
                doneParts.push(`Mode : ${modeLabel}`);
            }
            if (printer) {
                doneParts.push(`Printer : ${printer}`);
            }
            summary = compactText(`${filenameText} ${doneParts.join(' | ')}`, 220);
        }

        match = firstPart.match(/^Print completed:\s*(.+)$/i);
        if (match) {
            badge = 'DONE';
            tone = 'success';
            const filenameText = compactText(match[1].trim(), 130);
            const resultLabel = normalizeLogResultLabel(resultText);
            const modeLabel = normalizeLogModeLabel(modeText);
            const doneParts = [`Telah ${resultLabel} di Print`];
            if (modeLabel !== '') {
                doneParts.push(`Mode : ${modeLabel}`);
            }
            if (printer) {
                doneParts.push(`Printer : ${printer}`);
            }
            summary = compactText(`${filenameText} ${doneParts.join(' | ')}`, 220);
        }

        match = firstPart.match(/^Print success:\s*(.+)$/i);
        if (match) {
            badge = 'DONE';
            tone = 'success';
            const filenameText = compactText(match[1].trim(), 130);
            const modeLabel = normalizeLogModeLabel(modeText);
            const doneParts = ['Telah Berhasil di Print'];
            if (modeLabel !== '') {
                doneParts.push(`Mode : ${modeLabel}`);
            }
            if (printer) {
                doneParts.push(`Printer : ${printer}`);
            }
            summary = compactText(`${filenameText} ${doneParts.join(' | ')}`, 220);
        }

        match = firstPart.match(/^Print cancelled:\s*(.+)$/i);
        if (match) {
            badge = 'CANCEL';
            tone = 'warning';
            summary = withDetails(`Dibatalkan: ${match[1].trim()}`);
        }

        match = firstPart.match(/^Print denied:\s*(.+)$/i);
        if (match) {
            badge = 'DENY';
            tone = 'error';
            summary = withDetails(`Ditolak: ${match[1].trim()}`);
        }

        match = firstPart.match(/^Print failed:\s*(.+)$/i);
        if (match) {
            badge = 'ERROR';
            tone = 'error';
            summary = withDetails(`Gagal: ${match[1].trim()}`);
        }

        return {
            client,
            badge,
            tone,
            summary
        };
    }

    function buildCompactLogEntry(logEntry, sourceIndex = 0) {
        const envelope = parseLogEnvelope(logEntry);
        const parsed = parsePrintLogMessage(envelope.message);
        const cleanClient = stripClientIdentity(parsed.client || extractClientName(envelope.message) || 'System');
        const client = cleanClient || 'System';

        let tone = parsed.tone;
        if (!['info', 'success', 'warning', 'error'].includes(tone)) {
            tone = 'info';
        }
        if (tone === 'info' && envelope.level === 'error') {
            tone = 'error';
        }

        const badge = parsed.badge || 'PRINT';

        return {
            tone,
            badge,
            client,
            clientColor: getClientColorClass(client),
            time: shortTimeLabel(envelope.timestamp),
            summary: parsed.summary || compactText(envelope.message, 200),
            priority: getLogBadgePriority(badge),
            timeMs: parseLogTimestampMs(envelope.timestamp),
            sourceIndex: Number.isFinite(Number(sourceIndex)) ? Number(sourceIndex) : 0
        };
    }

    function normalizeLogFilter(filter) {
        return ['all', 'queue', 'sent', 'error'].includes(filter) ? filter : 'all';
    }

    function getLogFilterLabel(filter) {
        switch (normalizeLogFilter(filter)) {
            case 'queue':
                return 'Queue';
            case 'sent':
                return 'Sent';
            case 'error':
                return 'Error';
            default:
                return 'Semua';
        }
    }

    function isQueueCategory(item) {
        const badge = String(item?.badge ?? '').toUpperCase();
        return ['QUEUE', 'RETRY', 'START', 'PRINT'].includes(badge);
    }

    function isSentCategory(item) {
        const badge = String(item?.badge ?? '').toUpperCase();
        return ['SENT', 'DONE', 'SUCCESS'].includes(badge);
    }

    function isErrorCategory(item) {
        const badge = String(item?.badge ?? '').toUpperCase();
        const tone = String(item?.tone ?? '').toLowerCase();
        return tone === 'error' || ['ERROR', 'DENY', 'CANCEL'].includes(badge);
    }

    function applyLogFilter(items, filterMode) {
        const mode = normalizeLogFilter(filterMode);
        if (mode === 'all') {
            return items;
        }
        if (mode === 'queue') {
            return items.filter(isQueueCategory);
        }
        if (mode === 'sent') {
            return items.filter(isSentCategory);
        }
        if (mode === 'error') {
            return items.filter(isErrorCategory);
        }
        return items;
    }

    function renderLogPlaceholder(message, tone = 'info') {
        const normalizedTone = ['info', 'success', 'warning', 'error'].includes(tone) ? tone : 'info';
        return `
            <div class="log-entry ${normalizedTone}" data-client-color="color-neutral">
                <span class="log-time">--:--:--</span>
                <span class="log-badge">INFO</span>
                <span class="log-client">System</span>
                <span class="log-message">${htmlEscape(message)}</span>
            </div>
        `;
    }

    function updateLogFilterUi(displayedCount, totalCount) {
        const label = getLogFilterLabel(activeLogFilter);
        if (logFilterMeta) {
            logFilterMeta.textContent = `Filter: ${label} (${displayedCount}/${totalCount})`;
        }
        logFilterButtons.forEach((button) => {
            const mode = normalizeLogFilter(button.dataset.filter || 'all');
            button.classList.toggle('active', mode === activeLogFilter);
        });
    }

    function renderLogsFromCache(previousScrollTop, shouldAutoScroll) {
        const allItems = Array.isArray(cachedLogItems) ? cachedLogItems : [];
        const filteredItems = applyLogFilter(allItems, activeLogFilter);
        updateLogFilterUi(filteredItems.length, allItems.length);

        if (filteredItems.length > 0) {
            logsContainer.innerHTML = filteredItems.map(renderCompactLogEntry).join('');
        } else {
            const label = getLogFilterLabel(activeLogFilter).toLowerCase();
            logsContainer.innerHTML = renderLogPlaceholder(`Tidak ada log untuk filter "${label}"`, 'info');
        }

        if (shouldAutoScroll) {
            scrollLogsToBottom('auto');
        } else {
            const maxTop = Math.max(0, logsContainer.scrollHeight - logsContainer.clientHeight);
            setLogScrollTop(Math.min(previousScrollTop, maxTop));
        }
    }

    function setActiveLogFilter(filterMode, options = {}) {
        const mode = normalizeLogFilter(filterMode);
        const changed = activeLogFilter !== mode;
        activeLogFilter = mode;

        if (changed) {
            const keepScroll = options.keepScroll !== false;
            const topBefore = logsContainer.scrollTop;
            renderLogsFromCache(topBefore, !keepScroll ? true : false);
            logsAutoScrollEnabled = false;
            scheduleLogAutoScrollResume();
        } else {
            updateLogFilterUi(
                applyLogFilter(cachedLogItems, activeLogFilter).length,
                cachedLogItems.length
            );
        }
    }

    function renderCompactLogEntry(logEntry) {
        const item = (logEntry && typeof logEntry === 'object' && typeof logEntry.badge !== 'undefined')
            ? logEntry
            : buildCompactLogEntry(logEntry);
        const badgeKey = String(item.badge ?? 'INFO').toUpperCase();
        return `
            <div class="log-entry ${item.tone}" data-client-color="${item.clientColor}" data-badge="${htmlEscape(badgeKey)}">
                <span class="log-time">${htmlEscape(item.time)}</span>
                <span class="log-badge">${htmlEscape(badgeKey)}</span>
                <span class="log-client">${htmlEscape(item.client)}</span>
                <span class="log-message">${htmlEscape(item.summary)}</span>
            </div>
        `;
    }

    function setLogScrollTop(topValue) {
        isProgrammaticLogScroll = true;
        logsContainer.scrollTop = Math.max(0, Number(topValue) || 0);
        setTimeout(() => {
            isProgrammaticLogScroll = false;
        }, 60);
    }

    function scrollLogsToBottom(behavior = 'auto') {
        isProgrammaticLogScroll = true;
        if (typeof logsContainer.scrollTo === 'function') {
            logsContainer.scrollTo({ top: logsContainer.scrollHeight, behavior });
        } else {
            logsContainer.scrollTop = logsContainer.scrollHeight;
        }
        setTimeout(() => {
            isProgrammaticLogScroll = false;
        }, 80);
    }

    function scheduleLogAutoScrollResume() {
        if (logsIdleResumeTimer) {
            clearTimeout(logsIdleResumeTimer);
        }

        logsIdleResumeTimer = setTimeout(() => {
            logsAutoScrollEnabled = true;
            scrollLogsToBottom('smooth');
        }, LOG_IDLE_RESUME_MS);
    }

    function markLogUserInteraction() {
        const now = Date.now();
        if (now - lastLogInteractionMark < 120) {
            return;
        }
        lastLogInteractionMark = now;
        logsAutoScrollEnabled = false;
        scheduleLogAutoScrollResume();
    }

    function bindLogInteractionHandlers() {
        if (!logsContainer) {
            return;
        }

        const passiveEvents = ['wheel', 'touchstart', 'touchmove', 'mousedown', 'mousemove'];
        passiveEvents.forEach((eventName) => {
            logsContainer.addEventListener(eventName, markLogUserInteraction, { passive: true });
        });

        logsContainer.addEventListener('scroll', () => {
            if (isProgrammaticLogScroll) {
                return;
            }
            markLogUserInteraction();
        }, { passive: true });
    }

    function bindLogFilterHandlers() {
        if (!logFilterButtons.length) {
            return;
        }
        logFilterButtons.forEach((button) => {
            button.addEventListener('click', () => {
                const mode = button.dataset.filter || 'all';
                setActiveLogFilter(mode, { keepScroll: true });
            });
        });
        updateLogFilterUi(0, 0);
    }

    bindLogInteractionHandlers();
    bindLogFilterHandlers();

    function formatCountdown(remainingSeconds) {
        const safeSeconds = Math.max(0, Number.parseInt(remainingSeconds, 10) || 0);
        const mins = Math.floor(safeSeconds / 60);
        const secs = safeSeconds % 60;
        return `${mins}:${String(secs).padStart(2, '0')}`;
    }

    // Update file grid
    function updateFileGrid(force = false) {
        if (isFetchingFiles && !force) {
            return;
        }
        isFetchingFiles = true;

        fetch('api.php?action=get_files', {
            cache: 'no-store',
            credentials: 'same-origin'
        })
            .then(res => res.json())
            .then(data => {
                if (data.auth_required) {
                    stopRealtimeUpdates();
                    openNameModal();
                    fileGrid.innerHTML = `
                        <div class="empty-state" style="grid-column: 1/-1;">
                            <div class="empty-icon"><i class="fas fa-user-lock"></i></div>
                            <div>Silakan login terlebih dahulu</div>
                        </div>
                    `;
                    return;
                }

                const globalQueueCount = Number(data.global_queue_count ?? data.queue_count ?? 0);
                const globalStatus = data.global_status || (globalQueueCount > 0 ? 'Printing' : 'Ready');
                queueCountSpan.textContent = String(globalQueueCount);
                systemStatusSpan.textContent = globalStatus;

                if (data.success && data.files && data.files.length > 0) {
                    fileGrid.innerHTML = data.files.map(file => {
                        const safeNameJs = escapeJsSingleQuote(file.name);
                        const modeKey = normalizeClientPrintMode(file.print_mode || preferredPrintMode);
                        const safeModeJs = escapeJsSingleQuote(modeKey);
                        const modeLabel = getPrintModeLabel(modeKey);
                        const modeIcon = modeKey === 'grayscale' ? 'fa-droplet-slash' : 'fa-palette';
                        const previewButton = `
                            <button class="btn btn-preview-file" onclick="previewQueueFile('${safeNameJs}')" title="Preview file" aria-label="Preview file">
                                <i class="fas fa-eye"></i>
                            </button>
                        `;
                        let actionButtons = '';
                        const isUploaded = file.status === 'uploaded';
                        const isReady = file.status === 'ready';
                        const isPrinting = file.status === 'printing';
                        const isDone = file.status === 'completed' || file.status === 'done';
                        const isCancelled = file.status === 'cancelled';
                        const isFailed = file.status === 'failed';
                        const hasQueuePos = Number(file.queue_position) > 0;

                        if (isPrinting) {
                            actionButtons = `
                                <button class="btn btn-cancel" onclick="cancelPrint('${safeNameJs}')">
                                    <i class="fas fa-times"></i> Cancel
                                </button>
                            `;
                        } else if (isReady) {
                            actionButtons = `
                                <button class="btn btn-waiting" disabled>
                                    <i class="fas fa-hourglass-half"></i> Menunggu
                                </button>
                                <button class="btn btn-delete" onclick="deleteFile('${safeNameJs}')">
                                    <i class="fas fa-trash"></i> Hapus
                                </button>
                            `;
                        } else if (isDone) {
                            actionButtons = `
                                <button class="btn btn-print" id="print-${htmlEscape(file.name)}" onclick="printFile('${safeNameJs}', '${safeModeJs}')">
                                    <i class="fas fa-print"></i> Print Lagi
                                </button>
                                <button class="btn btn-delete" onclick="deleteFile('${safeNameJs}')">
                                    <i class="fas fa-trash"></i> Hapus
                                </button>
                            `;
                        } else if (isCancelled || isFailed) {
                            actionButtons = `
                                <button class="btn btn-retry" onclick="retryPrint('${safeNameJs}')">
                                    <i class="fas fa-redo"></i> Ulangi
                                </button>
                                <button class="btn btn-delete" onclick="deleteFile('${safeNameJs}')">
                                    <i class="fas fa-trash"></i> Hapus
                                </button>
                            `;
                        } else {
                            const printLabel = 'Print';
                            actionButtons = `
                                <button class="btn btn-print" id="print-${htmlEscape(file.name)}" onclick="printFile('${safeNameJs}', '${safeModeJs}')">
                                    <i class="fas fa-print"></i> ${printLabel}
                                </button>
                                <button class="btn btn-delete" onclick="deleteFile('${safeNameJs}')">
                                    <i class="fas fa-trash"></i> Hapus
                                </button>
                            `;
                        }

                        let statusText = file.status.toUpperCase();
                        if (isDone) {
                            statusText = 'SELESAI';
                        } else if (isPrinting) {
                            statusText = hasQueuePos ? `MENCETAK... #${Number(file.queue_position)}` : 'MENCETAK...';
                        } else if (isUploaded) {
                            statusText = 'SIAP CETAK';
                        } else if (isReady) {
                            statusText = hasQueuePos ? `MENUNGGU GILIRAN #${Number(file.queue_position)}` : 'MENUNGGU GILIRAN';
                        } else if (isCancelled) {
                            statusText = 'DIBATALKAN';
                        } else if (isFailed) {
                            statusText = 'ERROR CETAK';
                        }

                        let queuePositionHTML = '';
                        if ((isReady || isPrinting) && hasQueuePos) {
                            queuePositionHTML = `<div class="file-queue-pos"><i class="fas fa-hashtag"></i> Antrian #${Number(file.queue_position)}</div>`;
                        }

                        let countdownHTML = '';
                        if (isDone) {
                            const remaining = Number.isFinite(Number(file.countdown))
                                ? Math.max(0, Number(file.countdown))
                                : 0;
                            countdownHTML = remaining > 0
                                ? `<div class="file-countdown"><i class="fas fa-hourglass-end"></i> Akan dihapus dalam ${formatCountdown(remaining)}</div>`
                                : `<div class="file-countdown"><i class="fas fa-hourglass-end"></i> Menunggu penghapusan otomatis...</div>`;
                        }

                        let errorMessageHtml = '';
                        if (isFailed && file.error_message) {
                            errorMessageHtml = `<div class="file-error-message">${htmlEscape(file.error_message)}</div>`;
                        }

                        return `
                            <div class="file-card" id="file-card-${htmlEscape(file.name)}">
                                <div class="file-card-preview">
                                    ${previewButton}
                                </div>
                                <span class="file-icon"><i class="fas fa-file-pdf"></i></span>
                                <div class="file-name" title="${htmlEscape(file.originalName)}">
                                    ${htmlEscape(file.originalName)}
                                </div>
                                <div class="file-size">${formatFileSize(file.size)}</div>
                                <div class="file-print-mode ${modeKey}">
                                    <i class="fas ${modeIcon}"></i> Mode: ${htmlEscape(modeLabel)}
                                </div>
                                ${queuePositionHTML}
                                <div class="file-status ${file.status}">${statusText}</div>
                                ${errorMessageHtml}
                                ${countdownHTML}
                                <div class="file-actions">
                                    ${actionButtons}
                                </div>
                            </div>
                        `;
                    }).join('');
                } else {
                    fileGrid.innerHTML = `
                        <div class="empty-state" style="grid-column: 1/-1;">
                            <div class="empty-icon"><i class="fas fa-inbox"></i></div>
                            <div>Tidak ada file Anda dalam antrian saat ini</div>
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
            })
            .finally(() => {
                isFetchingFiles = false;
            });
    }

    // Update logs
    function updateLogs(force = false) {
        if (isFetchingLogs && !force) {
            return;
        }
        isFetchingLogs = true;

        fetch('api.php?action=get_logs', {
            cache: 'no-store',
            credentials: 'same-origin'
        })
            .then(res => res.json())
            .then(data => {
                const previousScrollTop = logsContainer.scrollTop;
                const shouldAutoScroll = logsAutoScrollEnabled;

                if (data.auth_required) {
                    stopRealtimeUpdates();
                    cachedLogItems = [];
                    updateLogFilterUi(0, 0);
                    logsContainer.innerHTML = renderLogPlaceholder('Silakan login terlebih dahulu', 'info');
                    openNameModal();
                    return;
                }

                if (data.success && data.logs && data.logs.length > 0) {
                    cachedLogItems = data.logs
                        .map((entry, index) => buildCompactLogEntry(entry, index))
                        .sort((a, b) => {
                            const byTime = (a.timeMs || 0) - (b.timeMs || 0);
                            if (byTime !== 0) {
                                return byTime;
                            }
                            const byPriority = (a.priority || 0) - (b.priority || 0);
                            if (byPriority !== 0) {
                                return byPriority;
                            }
                            return (a.sourceIndex || 0) - (b.sourceIndex || 0);
                        });
                } else {
                    cachedLogItems = [];
                }
                renderLogsFromCache(previousScrollTop, shouldAutoScroll);
            })
            .catch(err => console.error('Error fetching logs:', err))
            .finally(() => {
                isFetchingLogs = false;
            });
    }

    function previewQueueFile(filename) {
        if (!clientName) {
            showToast('Silakan login terlebih dahulu.', 'warning');
            openNameModal();
            return;
        }

        const previewUrl = 'api.php?action=preview_file&job_id=' + encodeURIComponent(filename);
        const previewWindow = window.open(previewUrl, '_blank', 'noopener,noreferrer');
        if (!previewWindow) {
            showToast('Popup preview diblokir browser. Izinkan popup lalu coba lagi.', 'warning');
        }
    }

    // Print file
    async function printFile(filename, suggestedMode = '') {
        if (!clientName) {
            showToast('Silakan login terlebih dahulu.', 'warning');
            openNameModal();
            return;
        }

        // Validasi bahwa user sudah membaca rules
        if (!hasReadRules) {
            showToast('Anda harus membaca syarat & ketentuan terlebih dahulu sebelum mencetak.', 'warning');
            openRulesModal();
            return;
        }

        const printChoice = await openPrintModeConfirm(filename, suggestedMode);
        if (!printChoice.confirmed) {
            return;
        }
        const chosenMode = normalizeClientPrintMode(printChoice.mode || preferredPrintMode);
        const printBtn = document.getElementById(`print-${filename}`);
        if (printBtn) {
            printBtn.disabled = true;
            printBtn.style.opacity = '0.5';
            printBtn.style.cursor = 'not-allowed';
        }

        try {
            const response = await fetch('api.php?action=print_file', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'job_id=' + encodeURIComponent(filename) + '&print_mode=' + encodeURIComponent(chosenMode)
            });
            const data = await response.json();

            if (data.auth_required) {
                showToast('Sesi login berakhir. Silakan login kembali.', 'warning');
                stopRealtimeUpdates();
                openNameModal();
                return;
            }

            if (!data.success) {
                showToast('Gagal: ' + (data.message || 'Gagal'), 'error');
                return;
            }

            const queuePos = Number(data.queue_position || 0);
            if (data.status === 'printing') {
                showToast(queuePos > 0 ? `Printing... #${queuePos}` : 'Printing...', 'success');
            } else if (data.status === 'ready') {
                showToast(
                    queuePos > 0
                        ? `Masuk antrian #${queuePos} (${getPrintModeLabel(chosenMode)})`
                        : (data.message || 'File masuk antrian print'),
                    'success'
                );
            } else {
                showToast(data.message || 'Perintah print dikirim.', 'success');
            }
        } catch (err) {
            console.error('Error:', err);
            showToast('Terjadi kesalahan saat mengirim perintah print', 'error');
        } finally {
            if (printBtn) {
                printBtn.disabled = false;
                printBtn.style.opacity = '1';
                printBtn.style.cursor = 'pointer';
            }
            updateFileGrid(true);
            updateLogs(true);
        }
    }

    // Cancel print
    async function cancelPrint(filename) {
        const shouldCancel = await openConfirm({
            tone: 'warning',
            title: 'Konfirmasi Cancel Print',
            message: `Batalkan pencetakan file "${filename}"?`,
            okText: 'Ya, Batalkan',
            cancelText: 'Batal'
        });
        if (!shouldCancel) {
            return;
        }

        fetch('api.php?action=cancel_print', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'job_id=' + encodeURIComponent(filename)
        })
        .then(res => res.json())
        .then(data => {
            if (data.auth_required) {
                showToast('Sesi login berakhir. Silakan login kembali.', 'warning');
                stopRealtimeUpdates();
                openNameModal();
                return;
            }

            if (data.success) {
                showToast('Pencetakan dibatalkan!', 'success');
            } else {
                showToast('Gagal: ' + (data.message || 'Gagal'), 'error');
            }

            // Update UI immediately
            updateFileGrid();
            updateLogs();
        })
        .catch(err => {
            console.error('Error:', err);
            showToast('Terjadi kesalahan saat membatalkan print', 'error');
        });
    }

    // Delete file
    async function deleteFile(filename) {
        const shouldDelete = await openConfirm({
            tone: 'danger',
            title: 'Konfirmasi Hapus File',
            message: `Hapus file "${filename}" dari antrian?`,
            okText: 'Ya, Hapus',
            cancelText: 'Batal'
        });
        if (!shouldDelete) {
            return;
        }

        fetch('api.php?action=delete_file', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'job_id=' + encodeURIComponent(filename)
        })
        .then(res => res.json())
        .then(data => {
            if (data.auth_required) {
                showToast('Sesi login berakhir. Silakan login kembali.', 'warning');
                stopRealtimeUpdates();
                openNameModal();
                return;
            }

            if (data.success) {
                showToast('File berhasil dihapus!', 'success');
            } else {
                showToast('Gagal menghapus file.', 'error');
            }
            updateFileGrid();
            updateLogs();
        });
    }

    // Retry print (for cancelled/failed files)
    async function retryPrint(filename) {
        const shouldRetry = await openConfirm({
            tone: 'warning',
            title: 'Konfirmasi Ulangi Print',
            message: `Ulangi pencetakan file "${filename}"?`,
            okText: 'Ya, Ulangi',
            cancelText: 'Batal'
        });
        if (!shouldRetry) {
            return;
        }

        fetch('api.php?action=reset_file_status', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'job_id=' + encodeURIComponent(filename)
        })
        .then(res => res.json())
        .then(data => {
            if (data.auth_required) {
                showToast('Sesi login berakhir. Silakan login kembali.', 'warning');
                stopRealtimeUpdates();
                openNameModal();
                return;
            }

            if (data.success) {
                const queuePos = Number(data.queue_position || 0);
                if (data.status === 'printing') {
                    showToast(queuePos > 0 ? `Printing... #${queuePos}` : 'Printing...', 'success');
                } else {
                    showToast(queuePos > 0 ? `Masuk antrian #${queuePos}` : 'Siap untuk dicetak ulang.', 'success');
                }
                updateFileGrid(true);
                updateLogs(true);
            } else {
                showToast('Gagal mereset file: ' + (data.message || 'Error'), 'error');
            }
        })
        .catch(err => {
            console.error('Error:', err);
            showToast('Terjadi kesalahan saat mereset file', 'error');
        });
    }

    // Upload file
    uploadForm.addEventListener('submit', async (e) => {
        e.preventDefault();

        if (!clientName) {
            showToast('Silakan login terlebih dahulu.', 'warning');
            openNameModal();
            return;
        }
        
        if (!fileInput.files[0]) {
            showToast('Pilih file terlebih dahulu!', 'warning');
            return;
        }

        uploadBtn.disabled = true;
        uploadBtn.innerHTML = '<span class="spinner"></span> Uploading...';

        const formData = new FormData(uploadForm);
        applyRenamedFilenameFromInput();
        
        // Add renamed filename if provided
        if (renamedFilename) {
            formData.append('renamed_filename', renamedFilename);
        }
        
        // Add hide filename preference
        if (hideFilenamePreference) {
            formData.append('hide_filename', '1');
        }
        
        try {
            const res = await fetch('api.php?action=upload_file', {
                method: 'POST',
                body: formData
            });
            const data = await res.json();

            if (data.auth_required) {
                showToast('Sesi login berakhir. Silakan login kembali.', 'warning');
                openNameModal();
                return;
            }

            if (data.success) {
                showToast(data.message || 'File berhasil di-upload. Klik Print untuk masuk antrian.', 'success');
                fileInput.value = '';
                uploadForm.reset();
                document.getElementById('filePreviewBox').style.display = 'none';
                uploadBtn.style.display = 'none';
                if (previewObjectUrl) {
                    URL.revokeObjectURL(previewObjectUrl);
                    previewObjectUrl = null;
                }
                renamedFilename = null;
                hideFilenamePreference = false;
                document.getElementById('previewRenameSection').style.display = 'none';
                document.getElementById('previewRenameInput').value = '';
                document.getElementById('hideFilenameCB').checked = false;
                updateFileGrid();
                updateLogs();
            } else {
                showToast('Gagal upload file: ' + (data.message || 'Silakan coba lagi.'), 'error');
            }
        } catch (err) {
            showToast('Error upload: ' + err.message, 'error');
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
            showToast('Hanya file PDF yang diperbolehkan!', 'warning');
        }
    });

    uploadBox.addEventListener('click', () => fileInput.click());

    // File input change - show preview and upload button
    fileInput.addEventListener('change', () => {
        const filePreviewBox = document.getElementById('filePreviewBox');
        const previewFilename = document.getElementById('previewFilename');
        const previewFilesize = document.getElementById('previewFilesize');

        if (previewObjectUrl) {
            URL.revokeObjectURL(previewObjectUrl);
            previewObjectUrl = null;
        }
        
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
        if (fileInput.files.length === 0) {
            showToast('Pilih file PDF terlebih dahulu.', 'warning');
            return;
        }

        const file = fileInput.files[0];
        if (previewObjectUrl) {
            URL.revokeObjectURL(previewObjectUrl);
            previewObjectUrl = null;
        }

        previewObjectUrl = URL.createObjectURL(file);
        const previewWindow = window.open(previewObjectUrl, '_blank', 'noopener,noreferrer');
        if (!previewWindow) {
            URL.revokeObjectURL(previewObjectUrl);
            previewObjectUrl = null;
            showToast('Popup preview diblokir browser. Izinkan popup lalu coba lagi.', 'warning');
            return;
        }

        setTimeout(() => {
            if (previewObjectUrl) {
                URL.revokeObjectURL(previewObjectUrl);
                previewObjectUrl = null;
            }
        }, 60000);
    });

    // Preview delete button - clear selection
    document.getElementById('previewDeleteBtn').addEventListener('click', (e) => {
        e.preventDefault();
        if (previewObjectUrl) {
            URL.revokeObjectURL(previewObjectUrl);
            previewObjectUrl = null;
        }
        fileInput.value = '';
        document.getElementById('filePreviewBox').style.display = 'none';
        uploadBtn.style.display = 'none';
        uploadBox.style.borderColor = 'var(--primary-color)';
        uploadBox.style.background = 'var(--light-bg)';
        renamedFilename = null;
        document.getElementById('previewRenameSection').style.display = 'none';
        document.getElementById('previewRenameInput').value = '';
        document.getElementById('hideFilenameCB').checked = false;
    });

    // Track renamed filename and hide preference
    let renamedFilename = null;
    let hideFilenamePreference = false;

    // Rename button click - toggle rename input
    document.getElementById('previewRenameBtn').addEventListener('click', (e) => {
        e.preventDefault();
        const renameSection = document.getElementById('previewRenameSection');
        const renameInput = document.getElementById('previewRenameInput');
        
        if (renameSection.style.display === 'none') {
            // Show rename input
            renameSection.style.display = 'block';
            renameInput.focus();
            // Pre-fill with current filename (without extension)
            if (renamedFilename) {
                renameInput.value = renamedFilename.replace(/\.pdf$/i, '');
            } else if (fileInput.files.length > 0) {
                renameInput.value = fileInput.files[0].name.replace(/\.pdf$/i, '');
            }
        } else {
            // Hide rename input
            renameSection.style.display = 'none';
        }
    });

    function applyRenamedFilenameFromInput() {
        const renameInput = document.getElementById('previewRenameInput');
        const newName = renameInput.value.trim();
        if (newName.length === 0) {
            renamedFilename = null;
            if (fileInput.files.length > 0) {
                document.getElementById('previewFilename').textContent = fileInput.files[0].name;
            }
            return;
        }

        const sanitized = newName.replace(/[^A-Za-z0-9 _().-]/g, '').trim();
        if (sanitized.length === 0) {
            renamedFilename = null;
            return;
        }

        renamedFilename = /\.pdf$/i.test(sanitized) ? sanitized : sanitized + '.pdf';
        document.getElementById('previewFilename').textContent = renamedFilename;
    }

    // Rename input - update filename on enter
    document.getElementById('previewRenameInput').addEventListener('keyup', (e) => {
        if (e.key === 'Enter') {
            applyRenamedFilenameFromInput();
            document.getElementById('previewRenameSection').style.display = 'none';
        }
    });

    document.getElementById('previewRenameInput').addEventListener('blur', () => {
        applyRenamedFilenameFromInput();
    });

    // Hide filename checkbox
    document.getElementById('hideFilenameCB').addEventListener('change', (e) => {
        hideFilenamePreference = e.target.checked;
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

    async function initializeApp() {
        const isLoggedIn = await checkClientStatus();
        if (!isLoggedIn) {
            stopRealtimeUpdates();
            return;
        }

        updateFileGrid();
        updateLogs();
        startRealtimeUpdates();
    }

    // Initialize
    initializeApp();
</script>

</body>
</html>


