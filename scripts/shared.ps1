function Get-ProjectRoot {
    return (Split-Path $PSScriptRoot -Parent)
}

function Read-EnvFile {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Path
    )

    $data = [ordered]@{}
    if (-not (Test-Path $Path)) {
        return $data
    }

    foreach ($line in Get-Content $Path) {
        $trimmed = $line.Trim()
        if ([string]::IsNullOrWhiteSpace($trimmed) -or $trimmed.StartsWith('#')) {
            continue
        }

        $pair = $trimmed -split '=', 2
        if ($pair.Count -ne 2) {
            continue
        }

        $key = $pair[0].Trim()
        $value = $pair[1].Trim()
        if (
            ($value.StartsWith('"') -and $value.EndsWith('"')) -or
            ($value.StartsWith("'") -and $value.EndsWith("'"))
        ) {
            $value = $value.Substring(1, $value.Length - 2)
        }

        $data[$key] = $value
    }

    return $data
}

function Get-LocalSettings {
    $projectRoot = Get-ProjectRoot
    $defaults = [ordered]@{
        APP_TIMEZONE = 'Europe/Berlin'
        APP_HOST = '127.0.0.1'
        APP_PORT = '8080'
        DB_HOST = 'localhost'
        DB_PORT = '3306'
        DB_NAME = 'danielle_local'
        DB_USER = 'root'
        DB_PASS = ''
        DB_CHARSET = 'utf8mb4'
        BOOTSTRAP_DB_ROOT_USER = 'root'
        BOOTSTRAP_DB_ROOT_PASS = ''
        XAMPP_ROOT = 'C:\xampp'
        XAMPP_PHP_EXE = 'C:\xampp\php\php.exe'
        XAMPP_MYSQL_EXE = 'C:\xampp\mysql\bin\mysql.exe'
        XAMPP_MYSQLD_EXE = 'C:\xampp\mysql\bin\mysqld.exe'
        XAMPP_MYSQL_DEFAULTS = 'C:\xampp\mysql\bin\my.ini'
    }

    foreach ($envPath in @(
        (Join-Path $projectRoot '.env.example'),
        (Join-Path $projectRoot '.env.local')
    )) {
        $parsed = Read-EnvFile -Path $envPath
        foreach ($entry in $parsed.GetEnumerator()) {
            $defaults[$entry.Key] = $entry.Value
        }
    }

    return [pscustomobject]$defaults
}

function Assert-FileExists {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Path,
        [Parameter(Mandatory = $true)]
        [string]$Label
    )

    if (-not (Test-Path $Path)) {
        throw "$Label wurde nicht gefunden: $Path"
    }
}

function Get-MySqlArgs {
    param(
        [Parameter(Mandatory = $true)]
        [pscustomobject]$Settings,
        [switch]$UseRoot,
        [string]$Database
    )

    $args = @('--default-character-set=utf8mb4')

    if ($UseRoot) {
        $args += @('-u', $Settings.BOOTSTRAP_DB_ROOT_USER)
        if (-not [string]::IsNullOrWhiteSpace($Settings.BOOTSTRAP_DB_ROOT_PASS)) {
            $args += "-p$($Settings.BOOTSTRAP_DB_ROOT_PASS)"
        }
    } else {
        $args += @('-h', $Settings.DB_HOST, '-P', $Settings.DB_PORT, '-u', $Settings.DB_USER)
        if (-not [string]::IsNullOrWhiteSpace($Settings.DB_PASS)) {
            $args += "-p$($Settings.DB_PASS)"
        }
    }

    if ($Database) {
        $args += $Database
    }

    return $args
}

function Test-TcpPort {
    param(
        [Parameter(Mandatory = $true)]
        [string]$HostName,
        [Parameter(Mandatory = $true)]
        [int]$Port
    )

    return (Test-NetConnection -ComputerName $HostName -Port $Port -WarningAction SilentlyContinue).TcpTestSucceeded
}

function Start-LocalMySqlIfNeeded {
    param(
        [Parameter(Mandatory = $true)]
        [pscustomobject]$Settings
    )

    if (Test-TcpPort -HostName $Settings.DB_HOST -Port ([int]$Settings.DB_PORT)) {
        return
    }

    Start-Process -FilePath $Settings.XAMPP_MYSQLD_EXE `
        -ArgumentList "--defaults-file=$($Settings.XAMPP_MYSQL_DEFAULTS)", '--standalone' `
        -WorkingDirectory $Settings.XAMPP_ROOT | Out-Null

    for ($i = 0; $i -lt 10; $i++) {
        Start-Sleep -Seconds 1
        if (Test-TcpPort -HostName $Settings.DB_HOST -Port ([int]$Settings.DB_PORT)) {
            return
        }
    }

    throw "MariaDB konnte nicht gestartet werden."
}
