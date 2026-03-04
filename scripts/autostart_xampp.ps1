$ErrorActionPreference = 'SilentlyContinue'

$xamppRoot = 'C:\xampp'
$apacheExe = Join-Path $xamppRoot 'apache\bin\httpd.exe'
$mysqlExe = Join-Path $xamppRoot 'mysql\bin\mysqld.exe'
$mysqlIni = Join-Path $xamppRoot 'mysql\bin\my.ini'
$controlExe = Join-Path $xamppRoot 'xampp-control.exe'

function Start-IfMissing {
    param(
        [string]$ProcessName,
        [string]$FilePath,
        [string[]]$Arguments = @(),
        [string]$WorkingDirectory = ''
    )

    if (-not (Get-Process -Name $ProcessName -ErrorAction SilentlyContinue)) {
        if (Test-Path $FilePath) {
            if ($WorkingDirectory -eq '') {
                $WorkingDirectory = Split-Path -Path $FilePath -Parent
            }

            Start-Process -FilePath $FilePath -ArgumentList $Arguments -WorkingDirectory $WorkingDirectory -WindowStyle Hidden
        }
    }
}

# Start XAMPP Control Panel (minimized) if not running
if (-not (Get-Process -Name 'xampp-control' -ErrorAction SilentlyContinue)) {
    if (Test-Path $controlExe) {
        Start-Process -FilePath $controlExe -WindowStyle Minimized
    }
}

# Start Apache and MySQL process mode if not running
Start-IfMissing -ProcessName 'httpd' -FilePath $apacheExe -WorkingDirectory (Join-Path $xamppRoot 'apache\bin')
Start-IfMissing -ProcessName 'mysqld' -FilePath $mysqlExe -Arguments @("--defaults-file=$mysqlIni", '--standalone') -WorkingDirectory (Join-Path $xamppRoot 'mysql\bin')
