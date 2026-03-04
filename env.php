<?php

if (!function_exists('loadEnvFile')) {
    function loadEnvFile($path) {
        static $loadedPaths = [];

        if (isset($loadedPaths[$path])) {
            return;
        }
        $loadedPaths[$path] = true;

        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || strpos($trimmed, '#') === 0) {
                continue;
            }

            $delimiterPos = strpos($trimmed, '=');
            if ($delimiterPos === false) {
                continue;
            }

            $key = trim(substr($trimmed, 0, $delimiterPos));
            $value = trim(substr($trimmed, $delimiterPos + 1));
            if ($key === '') {
                continue;
            }

            if ((substr($value, 0, 1) === '"' && substr($value, -1) === '"') ||
                (substr($value, 0, 1) === "'" && substr($value, -1) === "'")) {
                $value = substr($value, 1, -1);
            }

            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

if (!function_exists('envValue')) {
    function envValue($key, $default = null) {
        $value = getenv($key);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }
        return $value;
    }
}

