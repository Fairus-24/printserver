<?php
// Simple test file to verify the print server system is working
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

$uploadsDir = __DIR__ . '/uploads/';
$logsDir = __DIR__ . '/logs/';

echo "=== FIK Smart Print Server - System Test ===\n\n";

// Test 1: Check directories
echo "1. Directory Check:\n";
echo "   - uploads/ exists: " . (file_exists($uploadsDir) ? "✓ YES" : "✗ NO") . "\n";
echo "   - logs/ exists: " . (file_exists($logsDir) ? "✓ YES" : "✗ NO") . "\n";
echo "   - uploads/ writable: " . (is_writable($uploadsDir) ? "✓ YES" : "✗ NO") . "\n";
echo "   - logs/ writable: " . (is_writable($logsDir) ? "✓ YES" : "✗ NO") . "\n";

// Test 2: Check PHP files
echo "\n2. Required Files Check:\n";
$files = ['index.php', 'api.php', 'clear_session.php', 'queue.php'];
foreach ($files as $file) {
    echo "   - $file: " . (file_exists(__DIR__ . '/' . $file) ? "✓ YES" : "✗ NO") . "\n";
}

// Test 3: Check file uploads
echo "\n3. Uploaded Files Check:\n";
$uploadedFiles = scandir($uploadsDir);
$uploadedCount = count($uploadedFiles) - 2; // Subtract . and ..
echo "   - Files in queue: $uploadedCount\n";
if ($uploadedCount > 0) {
    foreach ($uploadedFiles as $file) {
        if ($file !== '.' && $file !== '..') {
            $size = filesize($uploadsDir . $file);
            echo "     • $file (" . number_format($size / 1024, 2) . " KB)\n";
        }
    }
}

// Test 4: Check session data
echo "\n4. Session Data Check:\n";
echo "   - Session files key: " . (isset($_SESSION['files']) ? "✓ YES" : "✗ NO") . "\n";
if (isset($_SESSION['files']) && count($_SESSION['files']) > 0) {
    echo "   - Files in session: " . count($_SESSION['files']) . "\n";
    foreach ($_SESSION['files'] as $key => $file) {
        echo "     • " . $file['original_name'] . " (Status: " . $file['status'] . ")\n";
    }
}
echo "   - Last job active: " . (isset($_SESSION['last_job']) ? "✓ YES" : "✗ NO") . "\n";

// Test 5: Check logs
echo "\n5. Log Files Check:\n";
$todayLog = $logsDir . 'printer_' . date('Y-m-d') . '.log';
if (file_exists($todayLog)) {
    $lines = file($todayLog);
    echo "   - Today's log: ✓ YES (" . count($lines) . " entries)\n";
    echo "   - Last 3 entries:\n";
    $lastLines = array_slice($lines, -3);
    foreach ($lastLines as $line) {
        echo "     • " . trim($line) . "\n";
    }
} else {
    echo "   - Today's log: ✗ NO (will be created on first upload)\n";
}

// Test 6: PHP configuration
echo "\n6. PHP Configuration Check:\n";
echo "   - PHP Version: " . phpversion() . "\n";
echo "   - Session support: " . (extension_loaded('session') ? "✓ YES" : "✗ NO") . "\n";
echo "   - File functions: " . (function_exists('file_put_contents') ? "✓ YES" : "✗ NO") . "\n";
echo "   - JSON support: " . (function_exists('json_encode') ? "✓ YES" : "✗ NO") . "\n";

echo "\n=== End of Test ===\n";
?>
