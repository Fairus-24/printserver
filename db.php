<?php

require_once __DIR__ . '/env.php';
loadEnvFile(__DIR__ . '/.env');

function getDatabaseConfig() {
    return [
        'host' => envValue('DB_HOST', '127.0.0.1'),
        'port' => envValue('DB_PORT', '3306'),
        'name' => envValue('DB_NAME', 'printserver'),
        'user' => envValue('DB_USER', 'root'),
        'pass' => envValue('DB_PASS', ''),
    ];
}

function getDatabaseConnection() {
    static $connection = null;

    if ($connection instanceof mysqli) {
        return $connection;
    }

    $config = getDatabaseConfig();
    mysqli_report(MYSQLI_REPORT_OFF);

    $connection = @new mysqli(
        $config['host'],
        $config['user'],
        $config['pass'],
        '',
        (int)$config['port']
    );

    if ($connection->connect_errno) {
        throw new RuntimeException('Koneksi MySQL gagal: ' . $connection->connect_error);
    }

    $databaseName = str_replace('`', '``', $config['name']);
    $createDatabaseSql = "CREATE DATABASE IF NOT EXISTS `{$databaseName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
    if (!$connection->query($createDatabaseSql)) {
        throw new RuntimeException('Gagal membuat database: ' . $connection->error);
    }

    if (!$connection->select_db($config['name'])) {
        throw new RuntimeException('Gagal memilih database: ' . $connection->error);
    }

    if (!$connection->set_charset('utf8mb4')) {
        throw new RuntimeException('Gagal set charset utf8mb4: ' . $connection->error);
    }

    initializeUsersTable($connection);
    initializePrintJobsTable($connection);

    return $connection;
}

function initializeUsersTable($connection) {
    static $initialized = false;
    if ($initialized) {
        return;
    }

    $createTableSql = "
        CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            nim_nipy VARCHAR(30) NOT NULL UNIQUE,
            full_name VARCHAR(120) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            role VARCHAR(30) DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    if (!$connection->query($createTableSql)) {
        throw new RuntimeException('Gagal membuat tabel users: ' . $connection->error);
    }

    $dummyUsers = [
        ['23123456', 'Mahasiswa Dummy', 'dummy12345', 'mahasiswa'],
        ['1987654321', 'Dosen Dummy', 'dummy12345', 'dosen'],
        ['19770001', 'Admin Dummy', 'admin12345', 'admin'],
    ];

    $checkStmt = $connection->prepare("SELECT id FROM users WHERE nim_nipy = ? LIMIT 1");
    $insertStmt = $connection->prepare(
        "INSERT INTO users (nim_nipy, full_name, password_hash, role) VALUES (?, ?, ?, ?)"
    );

    if (!$checkStmt || !$insertStmt) {
        if ($checkStmt) {
            $checkStmt->close();
        }
        if ($insertStmt) {
            $insertStmt->close();
        }
        throw new RuntimeException('Gagal menyiapkan seed user dummy: ' . $connection->error);
    }

    foreach ($dummyUsers as $dummyUser) {
        $nimNipy = $dummyUser[0];
        $fullName = $dummyUser[1];
        $plainPassword = $dummyUser[2];
        $role = $dummyUser[3];

        $checkStmt->bind_param('s', $nimNipy);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        $exists = $checkResult && $checkResult->num_rows > 0;
        if ($checkResult) {
            $checkResult->free();
        }

        if (!$exists) {
            $passwordHash = password_hash($plainPassword, PASSWORD_DEFAULT);
            $insertStmt->bind_param('ssss', $nimNipy, $fullName, $passwordHash, $role);
            if (!$insertStmt->execute()) {
                $checkStmt->close();
                $insertStmt->close();
                throw new RuntimeException('Gagal menambahkan user dummy: ' . $insertStmt->error);
            }
        }
    }

    $checkStmt->close();
    $insertStmt->close();

    $initialized = true;
}

function initializePrintJobsTable($connection) {
    static $initialized = false;
    if ($initialized) {
        return;
    }

    $createTableSql = "
        CREATE TABLE IF NOT EXISTS print_jobs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            owner_user_id INT UNSIGNED NOT NULL,
            owner_nim_nipy VARCHAR(30) NOT NULL,
            owner_name VARCHAR(120) NOT NULL,
            stored_filename VARCHAR(255) NOT NULL,
            original_filename VARCHAR(255) NOT NULL,
            file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
            hide_filename TINYINT(1) NOT NULL DEFAULT 0,
            print_mode VARCHAR(15) NOT NULL DEFAULT 'color',
            status VARCHAR(20) NOT NULL DEFAULT 'ready',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            printed_at DATETIME DEFAULT NULL,
            deleted_at DATETIME DEFAULT NULL,
            last_error VARCHAR(500) DEFAULT NULL,
            INDEX idx_print_jobs_owner_status (owner_user_id, status),
            INDEX idx_print_jobs_status_created (status, created_at),
            INDEX idx_print_jobs_deleted_at (deleted_at),
            INDEX idx_print_jobs_filename (stored_filename)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    if (!$connection->query($createTableSql)) {
        throw new RuntimeException('Gagal membuat tabel print_jobs: ' . $connection->error);
    }

    // Migrate old unique index on stored_filename (if exists) to non-unique.
    $databaseName = '';
    $dbNameResult = $connection->query("SELECT DATABASE() AS dbn");
    if ($dbNameResult) {
        $dbNameRow = $dbNameResult->fetch_assoc();
        $databaseName = (string)($dbNameRow['dbn'] ?? '');
        $dbNameResult->free();
    }
    $dbNameEscaped = $connection->real_escape_string($databaseName);
    $idxResult = $connection->query(
        "SELECT DISTINCT INDEX_NAME
         FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = '{$dbNameEscaped}'
           AND TABLE_NAME = 'print_jobs'
           AND COLUMN_NAME = 'stored_filename'
           AND NON_UNIQUE = 0"
    );
    if ($idxResult) {
        $toDrop = [];
        while ($row = $idxResult->fetch_assoc()) {
            $idxName = $row['INDEX_NAME'] ?? '';
            if ($idxName !== '' && strtoupper($idxName) !== 'PRIMARY') {
                $toDrop[] = $idxName;
            }
        }
        $idxResult->free();

        foreach ($toDrop as $idxName) {
            $safeIdx = str_replace('`', '``', $idxName);
            $connection->query("ALTER TABLE print_jobs DROP INDEX `{$safeIdx}`");
        }
    }

    $filenameIndexExists = false;
    $filenameIdxResult = $connection->query(
        "SELECT 1
         FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = '{$dbNameEscaped}'
           AND TABLE_NAME = 'print_jobs'
           AND INDEX_NAME = 'idx_print_jobs_filename'
         LIMIT 1"
    );
    if ($filenameIdxResult) {
        $filenameIndexExists = $filenameIdxResult->num_rows > 0;
        $filenameIdxResult->free();
    }
    if (!$filenameIndexExists) {
        $connection->query("CREATE INDEX idx_print_jobs_filename ON print_jobs (stored_filename)");
    }

    $hasLastError = false;
    $lastErrorResult = $connection->query(
        "SELECT 1
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = '{$dbNameEscaped}'
           AND TABLE_NAME = 'print_jobs'
           AND COLUMN_NAME = 'last_error'
         LIMIT 1"
    );
    if ($lastErrorResult) {
        $hasLastError = $lastErrorResult->num_rows > 0;
        $lastErrorResult->free();
    }
    if (!$hasLastError) {
        $connection->query("ALTER TABLE print_jobs ADD COLUMN last_error VARCHAR(500) DEFAULT NULL AFTER deleted_at");
    }

    $hasPrintMode = false;
    $printModeResult = $connection->query(
        "SELECT 1
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = '{$dbNameEscaped}'
           AND TABLE_NAME = 'print_jobs'
           AND COLUMN_NAME = 'print_mode'
         LIMIT 1"
    );
    if ($printModeResult) {
        $hasPrintMode = $printModeResult->num_rows > 0;
        $printModeResult->free();
    }
    if (!$hasPrintMode) {
        $connection->query("ALTER TABLE print_jobs ADD COLUMN print_mode VARCHAR(15) NOT NULL DEFAULT 'color' AFTER hide_filename");
    }

    $initialized = true;
}
