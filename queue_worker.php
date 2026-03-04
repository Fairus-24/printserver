<?php

date_default_timezone_set('Asia/Jakarta');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/printer.php';

function psWorkerDirs() {
    $uploadsDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR;
    $logsDir = __DIR__ . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR;

    if (!is_dir($uploadsDir)) {
        @mkdir($uploadsDir, 0777, true);
    }
    if (!is_dir($logsDir)) {
        @mkdir($logsDir, 0777, true);
    }

    return [
        'uploads' => $uploadsDir,
        'logs' => $logsDir,
    ];
}

function psWorkerLog($message, $type = 'print') {
    $dirs = psWorkerDirs();
    $logFile = $dirs['logs'] . 'printer_' . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    @file_put_contents($logFile, "[{$timestamp}] [{$type}] {$message}\n", FILE_APPEND);
}

function psWorkerSanitizeOutput($text, $maxLength = 350) {
    $line = trim((string)$text);
    if ($line === '') {
        return '';
    }

    $line = preg_replace('/\s+/', ' ', $line);
    if ($line === null) {
        $line = '';
    }
    $line = trim($line);

    if (strlen($line) > $maxLength) {
        $line = substr($line, 0, $maxLength - 3) . '...';
    }
    return $line;
}

function psWorkerNormalizePrintMode($mode) {
    $mode = strtolower(trim((string)$mode));
    if ($mode === 'bw' || $mode === 'blackwhite' || $mode === 'black_and_white' || $mode === 'monochrome') {
        $mode = 'grayscale';
    }
    if ($mode !== 'grayscale') {
        $mode = 'color';
    }
    return $mode;
}

function psWorkerRunSumatra($sumatraPath, $pdfPath, $printerName = null, $printMode = 'color') {
    $sumatraPath = (string)$sumatraPath;
    $pdfPath = (string)$pdfPath;
    $printerName = $printerName !== null ? trim((string)$printerName) : null;
    $printMode = psWorkerNormalizePrintMode($printMode);

    $tempDir = rtrim(sys_get_temp_dir(), "\\/") . DIRECTORY_SEPARATOR;
    $scriptFile = $tempDir . 'print_worker_' . uniqid('', true) . '.ps1';

    $escapedSumatra = str_replace("'", "''", $sumatraPath);
    $escapedPdf = str_replace("'", "''", $pdfPath);

    if ($printerName !== null && $printerName !== '') {
        $escapedPrinter = str_replace("'", "''", $printerName);
        $printCommand = "& '" . $escapedSumatra . "' -print-to '" . $escapedPrinter . "'";
    } else {
        $printCommand = "& '" . $escapedSumatra . "' -print-to-default";
    }

    if ($printMode === 'grayscale') {
        $printCommand .= " -print-settings 'monochrome'";
    }

    $printCommand .= " '" . $escapedPdf . "' -silent";

    $scriptContent = $printCommand . "\nexit \$LASTEXITCODE\n";
    if (@file_put_contents($scriptFile, $scriptContent) === false) {
        return [
            'ok' => false,
            'exit_code' => -1,
            'output' => 'Gagal menulis script print'
        ];
    }

    $command = 'powershell -ExecutionPolicy Bypass -NoProfile -File "' . $scriptFile . '" 2>&1';
    $outputLines = [];
    $exitCode = 0;
    @exec($command, $outputLines, $exitCode);
    @unlink($scriptFile);

    return [
        'ok' => ($exitCode === 0),
        'exit_code' => (int)$exitCode,
        'output' => trim(implode("\n", $outputLines))
    ];
}

function psWorkerRunPowerShellScript($scriptContent) {
    $tempDir = rtrim(sys_get_temp_dir(), "\\/") . DIRECTORY_SEPARATOR;
    $scriptFile = $tempDir . 'queue_worker_ps_' . uniqid('', true) . '.ps1';

    $scriptContent = (string)$scriptContent;
    if (@file_put_contents($scriptFile, $scriptContent) === false) {
        return [
            'ok' => false,
            'exit_code' => -1,
            'output' => 'Gagal menulis script PowerShell'
        ];
    }

    $command = 'powershell -ExecutionPolicy Bypass -NoProfile -File "' . $scriptFile . '" 2>&1';
    $outputLines = [];
    $exitCode = 0;
    @exec($command, $outputLines, $exitCode);
    @unlink($scriptFile);

    return [
        'ok' => ($exitCode === 0),
        'exit_code' => (int)$exitCode,
        'output' => trim(implode("\n", $outputLines))
    ];
}

function psWorkerParsePrintJobsJson($jsonText) {
    $jsonText = trim((string)$jsonText);
    if ($jsonText === '') {
        return [];
    }

    $decoded = json_decode($jsonText, true);
    if (!is_array($decoded)) {
        return [];
    }

    if (array_key_exists('ID', $decoded)) {
        $decoded = [$decoded];
    }

    $jobs = [];
    foreach ($decoded as $row) {
        if (!is_array($row)) {
            continue;
        }
        $jobId = (int)($row['ID'] ?? 0);
        if ($jobId <= 0) {
            continue;
        }
        $jobs[] = [
            'id' => $jobId,
            'document' => trim((string)($row['Document'] ?? '')),
            'status' => trim((string)($row['JobStatus'] ?? '')),
            'total_pages' => (int)($row['TotalPages'] ?? 0),
            'pages_printed' => (int)($row['PagesPrinted'] ?? 0),
        ];
    }

    return $jobs;
}

function psWorkerGetPrinterJobs($printerName) {
    $printerName = trim((string)$printerName);
    if ($printerName === '') {
        return [
            'ok' => false,
            'jobs' => [],
            'error' => 'Nama printer kosong'
        ];
    }

    $escapedPrinter = str_replace("'", "''", $printerName);
    $script = "\$ErrorActionPreference = 'SilentlyContinue'\n" .
        "\$jobs = @(Get-PrintJob -PrinterName '{$escapedPrinter}' | Select-Object ID,Document,JobStatus,TotalPages,PagesPrinted)\n" .
        "if (\$jobs.Count -eq 0) {\n" .
        "    '[]'\n" .
        "} else {\n" .
        "    \$jobs | ConvertTo-Json -Compress\n" .
        "}\n";

    $result = psWorkerRunPowerShellScript($script);
    if (!$result['ok']) {
        return [
            'ok' => false,
            'jobs' => [],
            'error' => psWorkerSanitizeOutput($result['output'] ?? '')
        ];
    }

    $jobs = psWorkerParsePrintJobsJson($result['output'] ?? '');
    return [
        'ok' => true,
        'jobs' => $jobs,
        'error' => ''
    ];
}

function psWorkerExtractJobIds(array $jobs) {
    $ids = [];
    foreach ($jobs as $job) {
        $id = (int)($job['id'] ?? 0);
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    $ids = array_values(array_unique($ids));
    sort($ids);
    return $ids;
}

function psWorkerCapturePrinterJobIds($printerName) {
    $snapshot = psWorkerGetPrinterJobs($printerName);
    if (!$snapshot['ok']) {
        return [
            'ok' => false,
            'ids' => [],
            'error' => $snapshot['error'] ?: 'Tidak bisa membaca antrian printer'
        ];
    }

    return [
        'ok' => true,
        'ids' => psWorkerExtractJobIds($snapshot['jobs']),
        'error' => ''
    ];
}

function psWorkerWaitForPrinterCompletion($printerName, array $baselineIds, $maxWaitSeconds = 900) {
    $printerName = trim((string)$printerName);
    if ($printerName === '') {
        return [
            'ok' => true,
            'tracked' => false,
            'reason' => 'monitor_skipped'
        ];
    }

    $baselineIds = array_values(array_unique(array_map('intval', $baselineIds)));
    $baselineLookup = [];
    foreach ($baselineIds as $id) {
        if ($id > 0) {
            $baselineLookup[(string)$id] = true;
        }
    }

    $startedAt = microtime(true);
    $submitWindowSeconds = 20;
    $pollMicroseconds = 800000;
    $trackedJobIds = [];
    $remainingTracked = [];

    while ((microtime(true) - $startedAt) <= (float)$maxWaitSeconds) {
        $snapshot = psWorkerGetPrinterJobs($printerName);
        if (!$snapshot['ok']) {
            return [
                'ok' => true,
                'tracked' => false,
                'reason' => 'monitor_unavailable',
                'error' => $snapshot['error'] ?? ''
            ];
        }

        $currentIds = psWorkerExtractJobIds($snapshot['jobs']);
        $currentLookup = [];
        foreach ($currentIds as $id) {
            $currentLookup[(string)$id] = true;
        }

        if (!$trackedJobIds) {
            $newJobIds = [];
            foreach ($currentIds as $id) {
                if (!isset($baselineLookup[(string)$id])) {
                    $newJobIds[] = $id;
                }
            }

            if ($newJobIds) {
                $trackedJobIds = array_values(array_unique($newJobIds));
                sort($trackedJobIds);
            } else {
                if ((microtime(true) - $startedAt) >= $submitWindowSeconds) {
                    return [
                        'ok' => true,
                        'tracked' => false,
                        'reason' => 'job_not_visible'
                    ];
                }
                usleep($pollMicroseconds);
                continue;
            }
        }

        $remainingTracked = [];
        foreach ($trackedJobIds as $trackedId) {
            if (isset($currentLookup[(string)$trackedId])) {
                $remainingTracked[] = (int)$trackedId;
            }
        }

        if (!$remainingTracked) {
            return [
                'ok' => true,
                'tracked' => true,
                'reason' => 'completed'
            ];
        }

        usleep($pollMicroseconds);
    }

    return [
        'ok' => false,
        'tracked' => !empty($trackedJobIds),
        'reason' => 'timeout',
        'remaining' => implode(',', $remainingTracked)
    ];
}

function psWorkerComputeSoftHoldSeconds($pdfPath) {
    $size = @filesize((string)$pdfPath);
    if (!is_int($size) || $size <= 0) {
        return 5;
    }

    $mb = $size / (1024 * 1024);
    $seconds = (int)ceil($mb * 1.8);
    if ($seconds < 4) {
        $seconds = 4;
    }
    if ($seconds > 12) {
        $seconds = 12;
    }
    return $seconds;
}

function psWorkerClaimNextReadyJob($db) {
    if (!$db->begin_transaction()) {
        return null;
    }

    try {
        $stmt = $db->prepare(
            "SELECT id, owner_name, owner_nim_nipy, stored_filename, original_filename, hide_filename, print_mode
             FROM print_jobs
             WHERE status = 'ready' AND deleted_at IS NULL
             ORDER BY updated_at ASC, id ASC
             LIMIT 1
             FOR UPDATE"
        );
        if (!$stmt) {
            $db->rollback();
            return null;
        }

        $stmt->execute();
        $result = $stmt->get_result();
        $job = $result ? $result->fetch_assoc() : null;
        if ($result) {
            $result->free();
        }
        $stmt->close();

        if (!$job) {
            $db->commit();
            return null;
        }

        $updateStmt = $db->prepare(
            "UPDATE print_jobs
             SET status = 'printing', last_error = NULL, updated_at = NOW()
             WHERE id = ? AND status = 'ready'"
        );
        if (!$updateStmt) {
            $db->rollback();
            return null;
        }

        $jobId = (int)$job['id'];
        $updateStmt->bind_param('i', $jobId);
        $ok = $updateStmt->execute();
        $affected = (int)$updateStmt->affected_rows;
        $updateStmt->close();

        if (!$ok || $affected < 1) {
            $db->rollback();
            return null;
        }

        $db->commit();
        return $job;
    } catch (Throwable $e) {
        $db->rollback();
        psWorkerLog('Queue worker transaction error: ' . $e->getMessage(), 'error');
        return null;
    }
}

function psWorkerMarkDone($db, $jobId) {
    $stmt = $db->prepare(
        "UPDATE print_jobs
         SET status = 'done', printed_at = NOW(), last_error = NULL, deleted_at = NULL, updated_at = NOW()
         WHERE id = ?"
    );
    if (!$stmt) {
        return false;
    }
    $jobId = (int)$jobId;
    $stmt->bind_param('i', $jobId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function psWorkerMarkFailed($db, $jobId, $errorMessage) {
    $errorMessage = trim((string)$errorMessage);
    if ($errorMessage === '') {
        $errorMessage = 'Unknown print error';
    }
    if (strlen($errorMessage) > 500) {
        $errorMessage = substr($errorMessage, 0, 500);
    }

    $stmt = $db->prepare(
        "UPDATE print_jobs
         SET status = 'failed', printed_at = NULL, last_error = ?, updated_at = NOW()
         WHERE id = ?"
    );
    if (!$stmt) {
        return false;
    }
    $jobId = (int)$jobId;
    $stmt->bind_param('si', $errorMessage, $jobId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function psWorkerQueuePosition($db, $jobId) {
    $jobId = (int)$jobId;
    if ($jobId <= 0) {
        return 1;
    }

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
        return 1;
    }

    $pos = 1;
    while ($row = $result->fetch_assoc()) {
        if ((int)$row['id'] === $jobId) {
            $result->free();
            return $pos;
        }
        $pos++;
    }
    $result->free();
    return 1;
}

function runPrintQueueWorker($maxJobs = 50) {
    $maxJobs = max(1, (int)$maxJobs);

    $lockFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'printserver_queue_worker.lock';
    $lockHandle = @fopen($lockFile, 'c');
    if (!$lockHandle) {
        psWorkerLog('Queue worker gagal membuat lock file', 'error');
        return ['ran' => false, 'reason' => 'lock_open_failed', 'processed' => 0];
    }

    if (!@flock($lockHandle, LOCK_EX | LOCK_NB)) {
        @fclose($lockHandle);
        return ['ran' => false, 'reason' => 'already_running', 'processed' => 0];
    }

    $processed = 0;

    try {
        $db = getDatabaseConnection();
        $dirs = psWorkerDirs();
        $sumatraPath = getSumatraPdfPath();
        $detectedPrinter = trim((string)detectPrinterName(''));
        if ($detectedPrinter === '' || stripos($detectedPrinter, 'Tidak terdeteksi') !== false) {
            $detectedPrinter = null;
        }

        while ($processed < $maxJobs) {
            $job = psWorkerClaimNextReadyJob($db);
            if (!$job) {
                break;
            }

            $processed++;
            $jobId = (int)$job['id'];
            $ownerName = (string)$job['owner_name'];
            $storedFilename = (string)$job['stored_filename'];
            $originalFilename = (string)$job['original_filename'];
            $hideFilename = (int)($job['hide_filename'] ?? 0) === 1;
            $printMode = psWorkerNormalizePrintMode($job['print_mode'] ?? 'color');
            $modeLabel = $printMode === 'grayscale' ? 'Hitam Putih' : 'Berwarna';
            $displayFilename = $hideFilename ? '****.pdf' : $originalFilename;
            $pdfPath = $dirs['uploads'] . $storedFilename;
            $queuePos = psWorkerQueuePosition($db, $jobId);

            if (!is_file($sumatraPath)) {
                $error = 'Aplikasi SumatraPDF tidak ditemukan di: ' . $sumatraPath;
                psWorkerMarkFailed($db, $jobId, $error);
                psWorkerLog(
                    "Print failed: {$displayFilename} | Client: {$ownerName} | Mode: {$modeLabel} | Error: {$error}",
                    'print'
                );
                continue;
            }

            if (!is_file($pdfPath)) {
                $error = 'File PDF tidak ditemukan pada storage';
                psWorkerMarkFailed($db, $jobId, $error);
                psWorkerLog(
                    "Print failed: {$displayFilename} | Client: {$ownerName} | Mode: {$modeLabel} | Error: {$error}",
                    'print'
                );
                continue;
            }

            $usedPrinter = $detectedPrinter ?: 'Default';
            $monitorPrinterName = $detectedPrinter;
            $monitorBaselineIds = [];
            if ($monitorPrinterName !== null) {
                $baselineSnapshot = psWorkerCapturePrinterJobIds($monitorPrinterName);
                if ($baselineSnapshot['ok']) {
                    $monitorBaselineIds = $baselineSnapshot['ids'];
                }
            }
            psWorkerLog(
                "Print started: {$displayFilename} | Client: {$ownerName} | Mode: {$modeLabel} | Status: sedang dalam proses print...",
                'print'
            );
            $result = psWorkerRunSumatra($sumatraPath, $pdfPath, $detectedPrinter, $printMode);

            if (!$result['ok'] && $detectedPrinter !== null) {
                $fallbackPrinter = trim((string)detectPrinterName(''));
                if ($fallbackPrinter === '' || stripos($fallbackPrinter, 'Tidak terdeteksi') !== false) {
                    $fallbackPrinter = null;
                }

                $fallbackBaselineIds = [];
                if ($fallbackPrinter !== null) {
                    $fallbackBaselineSnapshot = psWorkerCapturePrinterJobIds($fallbackPrinter);
                    if ($fallbackBaselineSnapshot['ok']) {
                        $fallbackBaselineIds = $fallbackBaselineSnapshot['ids'];
                    }
                }

                $fallbackResult = psWorkerRunSumatra($sumatraPath, $pdfPath, null, $printMode);
                if ($fallbackResult['ok']) {
                    $result = $fallbackResult;
                    $usedPrinter = $fallbackPrinter ?: 'Default';
                    $monitorPrinterName = $fallbackPrinter;
                    $monitorBaselineIds = $fallbackBaselineIds;
                }
            }

            $outputSummary = psWorkerSanitizeOutput($result['output'] ?? '');

            if ($result['ok']) {
                $waitResult = psWorkerWaitForPrinterCompletion($monitorPrinterName, $monitorBaselineIds, 900);
                if (!$waitResult['ok']) {
                    $waitError = 'Timeout menunggu antrian printer selesai';
                    if (!empty($waitResult['remaining'])) {
                        $waitError .= ' | Job tersisa: ' . $waitResult['remaining'];
                    }
                    psWorkerMarkFailed($db, $jobId, $waitError);
                    psWorkerLog(
                        "Print failed: {$displayFilename} | Client: {$ownerName} | Printer: {$usedPrinter} | Mode: {$modeLabel} | Error: {$waitError}",
                        'print'
                    );
                    continue;
                }

                if (empty($waitResult['tracked'])) {
                    $softHoldSeconds = psWorkerComputeSoftHoldSeconds($pdfPath);
                    if ($softHoldSeconds > 0) {
                        sleep($softHoldSeconds);
                    }
                }

                psWorkerMarkDone($db, $jobId);
                $resultLabel = empty($waitResult['tracked']) ? 'Berhasil (Spooler)' : 'Berhasil';
                psWorkerLog(
                    "Print done: {$displayFilename} | Client: {$ownerName} | Printer: {$usedPrinter} | Mode: {$modeLabel} | Result: {$resultLabel}" .
                    ($outputSummary !== '' ? " | Output: {$outputSummary}" : ''),
                    'print'
                );
                continue;
            }

            $exitCode = (int)($result['exit_code'] ?? -1);
            $error = 'Exit code ' . $exitCode;
            if ($outputSummary !== '') {
                $error .= ' | ' . $outputSummary;
            }

            psWorkerMarkFailed($db, $jobId, $error);
            psWorkerLog(
                "Print failed: {$displayFilename} | Client: {$ownerName} | Printer: {$usedPrinter} | Mode: {$modeLabel} | Error: {$error}",
                'print'
            );
        }
    } catch (Throwable $e) {
        psWorkerLog('Queue worker fatal error: ' . $e->getMessage(), 'error');
    }

    @flock($lockHandle, LOCK_UN);
    @fclose($lockHandle);

    return ['ran' => true, 'reason' => 'ok', 'processed' => $processed];
}

if (PHP_SAPI === 'cli' && !defined('PRINTSERVER_QUEUE_WORKER_NO_AUTO_RUN')) {
    $maxJobs = 50;
    if (isset($argv) && is_array($argv) && isset($argv[1])) {
        $maxJobs = (int)$argv[1];
    }
    runPrintQueueWorker($maxJobs);
    exit(0);
}
