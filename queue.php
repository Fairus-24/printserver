<?php
session_start();
header('Content-Type: application/json');

$printer = "EPSON L120 Series";
$action = $_GET['action'] ?? '';

if($action == 'check_status') {
    $jobId = $_POST['job_id'] ?? '';
    
    if(empty($jobId)) {
        echo json_encode(['success' => false, 'message' => 'Job ID tidak ditemukan']);
        exit;
    }
    
    $uploadsDir = __DIR__ . "/uploads/";
    $jobFile = $uploadsDir . $jobId;
    
    if(file_exists($jobFile)) {
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
} 
elseif($action == 'delete_file') {
    $jobId = $_POST['job_id'] ?? '';
    
    if(empty($jobId)) {
        echo json_encode(['success' => false, 'message' => 'Job ID tidak ditemukan']);
        exit;
    }
    
    $uploadsDir = __DIR__ . "/uploads/";
    $jobFile = $uploadsDir . $jobId;
    
    if(file_exists($jobFile)) {
        if(unlink($jobFile)) {
            echo json_encode(['success' => true, 'message' => 'File berhasil dihapus']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Gagal menghapus file']);
        }
    } else {
        echo json_encode(['success' => true, 'message' => 'File tidak ditemukan']);
    }
}
else {
    $command = 'powershell -Command "Get-PrintJob -PrinterName \"' . $printer . '\" | Select Id,DocumentName,JobStatus,PagesPrinted | ConvertTo-Json -Compress"';
    
    exec($command, $output, $result);
    
    if ($result === 0 && !empty($output)) {
        echo implode("", $output);
    } else {
        echo json_encode([]);
    }
}
?>