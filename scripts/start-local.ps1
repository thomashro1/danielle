. "$PSScriptRoot\shared.ps1"

$ErrorActionPreference = 'Stop'
$settings = Get-LocalSettings
$projectRoot = Get-ProjectRoot
$runtimeDir = Join-Path $projectRoot '.local'
$pidFile = Join-Path $runtimeDir 'php-server.pid'
$logFile = Join-Path $runtimeDir 'php-server.log'
$errorLogFile = Join-Path $runtimeDir 'php-server.error.log'
$baseUrl = "http://$($settings.APP_HOST):$($settings.APP_PORT)"

Assert-FileExists -Path $settings.XAMPP_PHP_EXE -Label 'php.exe'

New-Item -ItemType Directory -Path $runtimeDir -Force | Out-Null

& (Join-Path $PSScriptRoot 'bootstrap-local.ps1')

if (Test-Path $pidFile) {
    $existingPid = (Get-Content $pidFile -Raw).Trim()
    if ($existingPid) {
        $running = Get-Process -Id ([int]$existingPid) -ErrorAction SilentlyContinue
        if ($running) {
            Write-Host "PHP-Server laeuft bereits unter $baseUrl (PID $existingPid)."
            return
        }
    }

    Remove-Item $pidFile -Force
}

if (Test-Path $logFile) {
    Remove-Item $logFile -Force
}

if (Test-Path $errorLogFile) {
    Remove-Item $errorLogFile -Force
}

$phpArgs = @(
    '-d', 'extension=imap',
    '-S', "$($settings.APP_HOST):$($settings.APP_PORT)",
    '-t', $projectRoot
)

$process = Start-Process -FilePath $settings.XAMPP_PHP_EXE `
    -ArgumentList $phpArgs `
    -WorkingDirectory $projectRoot `
    -RedirectStandardOutput $logFile `
    -RedirectStandardError $errorLogFile `
    -PassThru

Set-Content -Path $pidFile -Value $process.Id
Start-Sleep -Seconds 2

try {
    $null = Invoke-WebRequest -Uri "$baseUrl/index.html" -UseBasicParsing -TimeoutSec 10
} catch {
    throw "Der lokale PHP-Server wurde gestartet, antwortet aber nicht sauber. Logs: $logFile / $errorLogFile"
}

Write-Host "Lokaler Server laeuft: $baseUrl"
Write-Host "Backoffice: $baseUrl/backoffice_login.html"
Write-Host "Kundenportal: $baseUrl/customer_login.html"
