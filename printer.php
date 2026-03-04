<?php

require_once __DIR__ . '/env.php';
loadEnvFile(__DIR__ . '/.env');

if (!function_exists('sanitizePrinterOutput')) {
    function sanitizePrinterOutput($output) {
        if (!is_string($output)) {
            return '';
        }

        $lines = preg_split('/\r\n|\r|\n/', trim($output));
        if (!$lines) {
            return '';
        }

        foreach ($lines as $line) {
            $candidate = trim($line);
            if ($candidate !== '' && stripos($candidate, 'Name') !== 0) {
                return $candidate;
            }
        }

        return '';
    }
}

if (!function_exists('isVirtualPrinterName')) {
    function isVirtualPrinterName($printerName) {
        $name = strtolower(trim((string)$printerName));
        if ($name === '') {
            return false;
        }

        $virtualPatterns = [
            'onenote',
            'xps',
            'pdf',
            'fax',
            'snagit',
            'cutepdf',
            'adobe pdf',
            'foxit',
            'save to',
        ];

        foreach ($virtualPatterns as $pattern) {
            if (strpos($name, $pattern) !== false) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('parsePrinterJsonList')) {
    function parsePrinterJsonList($json) {
        if (!is_string($json) || trim($json) === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        // Single object case from ConvertTo-Json when only one row
        if (isset($decoded['Name'])) {
            $decoded = [$decoded];
        }

        $printers = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string)($row['Name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $printers[] = [
                'name' => $name,
                'default' => (bool)($row['Default'] ?? false),
                'work_offline' => (bool)($row['WorkOffline'] ?? false),
                'status' => (string)($row['PrinterStatus'] ?? ''),
            ];
        }
        return $printers;
    }
}

if (!function_exists('queryPrintersFromPowerShell')) {
    function queryPrintersFromPowerShell() {
        $command = 'powershell -NoProfile -ExecutionPolicy Bypass -Command "Get-Printer | Select-Object Name,Default,PrinterStatus,WorkOffline | ConvertTo-Json -Compress" 2>nul';
        $output = @shell_exec($command);
        return parsePrinterJsonList((string)$output);
    }
}

if (!function_exists('chooseBestPrinterName')) {
    function chooseBestPrinterName(array $printers) {
        if (!$printers) {
            return '';
        }

        $defaultPhysicalOnline = [];
        $physicalOnline = [];
        $defaultPhysical = [];
        $physicalAny = [];
        $defaultAnyOnline = [];
        $anyOnline = [];
        $defaultAny = [];
        $any = [];

        foreach ($printers as $printer) {
            $name = (string)($printer['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $isDefault = !empty($printer['default']);
            $isOnline = empty($printer['work_offline']);
            $isVirtual = isVirtualPrinterName($name);

            if ($isDefault && !$isVirtual && $isOnline) {
                $defaultPhysicalOnline[] = $name;
            }
            if (!$isVirtual && $isOnline) {
                $physicalOnline[] = $name;
            }
            if ($isDefault && !$isVirtual) {
                $defaultPhysical[] = $name;
            }
            if (!$isVirtual) {
                $physicalAny[] = $name;
            }
            if ($isDefault && $isOnline) {
                $defaultAnyOnline[] = $name;
            }
            if ($isOnline) {
                $anyOnline[] = $name;
            }
            if ($isDefault) {
                $defaultAny[] = $name;
            }
            $any[] = $name;
        }

        $priorityGroups = [
            $defaultPhysicalOnline,
            $physicalOnline,
            $defaultPhysical,
            $physicalAny,
            $defaultAnyOnline,
            $anyOnline,
            $defaultAny,
            $any,
        ];

        foreach ($priorityGroups as $group) {
            if (!empty($group[0])) {
                return $group[0];
            }
        }
        return '';
    }
}

if (!function_exists('detectPrinterName')) {
    function detectPrinterName($fallback = 'Tidak terdeteksi') {
        static $detected = null;
        if ($detected !== null) {
            return $detected;
        }

        $configured = trim((string)envValue('PRINTER_NAME', ''));
        if ($configured !== '') {
            $detected = $configured;
            return $detected;
        }

        $cacheFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'printserver_printer_cache.json';
        $cacheTtlSeconds = 20;
        if (is_file($cacheFile)) {
            $cacheAge = time() - (int)@filemtime($cacheFile);
            if ($cacheAge >= 0 && $cacheAge <= $cacheTtlSeconds) {
                $cachedRaw = @file_get_contents($cacheFile);
                $cached = json_decode((string)$cachedRaw, true);
                $cachedName = trim((string)($cached['name'] ?? ''));
                if ($cachedName !== '') {
                    $detected = $cachedName;
                    return $detected;
                }
            }
        }

        $printers = queryPrintersFromPowerShell();
        $selectedPrinter = chooseBestPrinterName($printers);
        if ($selectedPrinter !== '') {
            $detected = $selectedPrinter;
            @file_put_contents($cacheFile, json_encode(['name' => $selectedPrinter]));
            return $detected;
        }

        $commands = [
            'powershell -NoProfile -ExecutionPolicy Bypass -Command "$p = Get-Printer | Where-Object {`$_.Default -eq `$true} | Select-Object -First 1 -ExpandProperty Name; if (`$p) { `$p }" 2>nul',
            'powershell -NoProfile -ExecutionPolicy Bypass -Command "Get-Printer | Select-Object -First 1 -ExpandProperty Name" 2>nul',
            'wmic printer where "default=true" get name 2>nul',
        ];

        $virtualCandidate = '';
        foreach ($commands as $command) {
            $output = @shell_exec($command);
            $name = sanitizePrinterOutput($output);
            if ($name !== '') {
                if (isVirtualPrinterName($name)) {
                    if ($virtualCandidate === '') {
                        $virtualCandidate = $name;
                    }
                    continue;
                }
                $detected = $name;
                @file_put_contents($cacheFile, json_encode(['name' => $name]));
                return $detected;
            }
        }

        if ($virtualCandidate !== '') {
            $detected = $virtualCandidate;
            @file_put_contents($cacheFile, json_encode(['name' => $virtualCandidate]));
            return $detected;
        }

        $detected = $fallback;
        return $detected;
    }
}

if (!function_exists('getSumatraPdfPath')) {
    function getSumatraPdfPath() {
        return envValue('SUMATRA_PDF_PATH', 'C:\\Users\\LENOVO\\AppData\\Local\\SumatraPDF\\SumatraPDF.exe');
    }
}
