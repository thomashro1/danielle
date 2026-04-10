. "$PSScriptRoot\shared.ps1"

$projectRoot = Get-ProjectRoot
$pidFile = Join-Path $projectRoot '.local\php-server.pid'

if (-not (Test-Path $pidFile)) {
    Write-Host 'Kein lokaler PHP-Server eingetragen.'
    exit 0
}

$serverPid = (Get-Content $pidFile -Raw).Trim()
if ($serverPid) {
    $process = Get-Process -Id ([int]$serverPid) -ErrorAction SilentlyContinue
    if ($process) {
        Stop-Process -Id ([int]$serverPid)
        Write-Host "PHP-Server mit PID $serverPid wurde gestoppt."
    } else {
        Write-Host "PID $serverPid war nicht mehr aktiv."
    }
}

Remove-Item $pidFile -Force
