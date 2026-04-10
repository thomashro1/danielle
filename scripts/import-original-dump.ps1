. "$PSScriptRoot\shared.ps1"

$ErrorActionPreference = 'Stop'
$settings = Get-LocalSettings
$projectRoot = Get-ProjectRoot
$backupDir = Join-Path $projectRoot '.local\backups'
$importLogPath = Join-Path $projectRoot '.local\last-import.log'
$dumpPath = Join-Path $projectRoot 'c18x5xqr2_mysql_service_one_com.sql'
$dumpPathForMySql = $dumpPath -replace '\\', '/'
$sourceDbName = $settings.DB_NAME
$targetDbName = 'c18x5xqr2_careconnect'
$mysqldumpExe = Join-Path $settings.XAMPP_ROOT 'mysql\bin\mysqldump.exe'
$backupPath = Join-Path $backupDir ("{0}-{1}.sql" -f $sourceDbName, (Get-Date -Format 'yyyyMMdd-HHmmss'))

Assert-FileExists -Path $settings.XAMPP_MYSQL_EXE -Label 'mysql.exe'
Assert-FileExists -Path $mysqldumpExe -Label 'mysqldump.exe'
Assert-FileExists -Path $dumpPath -Label 'Original-Dump'

New-Item -ItemType Directory -Path $backupDir -Force | Out-Null

Start-LocalMySqlIfNeeded -Settings $settings

if (-not [string]::IsNullOrWhiteSpace($sourceDbName)) {
    & $mysqldumpExe -u $settings.BOOTSTRAP_DB_ROOT_USER --default-character-set=utf8mb4 --databases $sourceDbName > $backupPath
    if ($LASTEXITCODE -ne 0) {
        throw 'Das Backup der bisherigen lokalen Datenbank ist fehlgeschlagen.'
    }
}

& $settings.XAMPP_MYSQL_EXE -u $settings.BOOTSTRAP_DB_ROOT_USER -e "DROP DATABASE IF EXISTS $targetDbName;"
if ($LASTEXITCODE -ne 0) {
    throw 'Die Ziel-Datenbank konnte nicht geleert werden.'
}

& $settings.XAMPP_MYSQL_EXE -u $settings.BOOTSTRAP_DB_ROOT_USER -e "SET GLOBAL max_allowed_packet = 1073741824;"
if ($LASTEXITCODE -ne 0) {
    throw 'max_allowed_packet konnte nicht gesetzt werden.'
}

$importOutput = & $settings.XAMPP_MYSQL_EXE -u $settings.BOOTSTRAP_DB_ROOT_USER --max_allowed_packet=1G -e "source $dumpPathForMySql" 2>&1
($importOutput | Out-String) | Set-Content -Path $importLogPath
if ($LASTEXITCODE -ne 0 -or ($importOutput | Where-Object { $_ -match '^ERROR ' })) {
    throw 'Der Original-Dump konnte nicht importiert werden.'
}

$envPath = Join-Path $projectRoot '.env.local'
$envContent = Get-Content $envPath
$updatedContent = foreach ($line in $envContent) {
    if ($line -match '^DB_NAME=') {
        "DB_NAME=$targetDbName"
    } else {
        $line
    }
}
Set-Content -Path $envPath -Value $updatedContent

Write-Host "Backup erstellt: $backupPath"
Write-Host "Import-Log: $importLogPath"
Write-Host "Lokale App verwendet jetzt DB: $targetDbName"
