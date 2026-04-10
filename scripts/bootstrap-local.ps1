. "$PSScriptRoot\shared.ps1"

$ErrorActionPreference = 'Stop'
$settings = Get-LocalSettings
$projectRoot = Get-ProjectRoot

Assert-FileExists -Path $settings.XAMPP_MYSQL_EXE -Label 'mysql.exe'
Assert-FileExists -Path $settings.XAMPP_MYSQLD_EXE -Label 'mysqld.exe'
Assert-FileExists -Path $settings.XAMPP_MYSQL_DEFAULTS -Label 'my.ini'

$schemaPath = Join-Path $projectRoot 'database\01-schema.sql'
$seedPath = Join-Path $projectRoot 'database\02-seed.sql'

Assert-FileExists -Path $schemaPath -Label 'Schema-Datei'
Assert-FileExists -Path $seedPath -Label 'Seed-Datei'

Start-LocalMySqlIfNeeded -Settings $settings

$rootArgs = Get-MySqlArgs -Settings $settings -UseRoot
$databaseSql = @"
CREATE DATABASE IF NOT EXISTS $($settings.DB_NAME) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
"@

if ($settings.DB_USER -ne $settings.BOOTSTRAP_DB_ROOT_USER -or -not [string]::IsNullOrWhiteSpace($settings.DB_PASS)) {
    $databaseSql += @"
CREATE USER IF NOT EXISTS '$($settings.DB_USER)'@'localhost' IDENTIFIED BY '$($settings.DB_PASS)';
GRANT ALL PRIVILEGES ON $($settings.DB_NAME).* TO '$($settings.DB_USER)'@'localhost';
FLUSH PRIVILEGES;
"@
}

& $settings.XAMPP_MYSQL_EXE @rootArgs -e $databaseSql
if ($LASTEXITCODE -ne 0) {
    throw 'Datenbank oder Benutzer konnte nicht angelegt werden.'
}

$appArgs = Get-MySqlArgs -Settings $settings

Get-Content -Raw $schemaPath | & $settings.XAMPP_MYSQL_EXE @appArgs $settings.DB_NAME
if ($LASTEXITCODE -ne 0) {
    throw 'Schema konnte nicht importiert werden.'
}

Get-Content -Raw $seedPath | & $settings.XAMPP_MYSQL_EXE @appArgs $settings.DB_NAME
if ($LASTEXITCODE -ne 0) {
    throw 'Seed-Daten konnten nicht importiert werden.'
}

Write-Host "Lokale Datenbank ist bereit: $($settings.DB_NAME)"
Write-Host 'Backoffice-Login: demo.admin / demo1234'
Write-Host 'Kunden-Login: kunde@example.com / demo1234'
