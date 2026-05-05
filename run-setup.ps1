#Requires -Version 7.0
[CmdletBinding()]
param(
    [string]$EnvNew      = (Join-Path $PSScriptRoot '.env-new'),
    [string]$EnvDb       = (Join-Path $PSScriptRoot '.env-db'),
    [string]$ScriptLocal = (Join-Path $PSScriptRoot 'setup-db-and-nginx.sh'),
    [string]$NewHost     = '178.253.42.235',
    [string]$LogDir      = (Join-Path $PSScriptRoot 'logs')
)
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$pwd = (Get-Content $EnvNew -Raw -Encoding UTF8).Trim()

# DB-креды из .env-db (KEY=VALUE формат)
if (-not (Test-Path $EnvDb)) { throw "$EnvDb не найден — скопируйте .env-db.example в .env-db и заполните" }
$dbVars = @{}
Get-Content $EnvDb -Encoding UTF8 | ForEach-Object {
    if ($_ -match '^\s*(#|$)') { return }
    if ($_ -match '^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$') { $dbVars[$Matches[1]] = $Matches[2].Trim().Trim('"').Trim("'") }
}
foreach ($k in 'DB_USER','DB_PASS') {
    if (-not $dbVars[$k]) { throw "В $EnvDb не задан $k" }
}
New-Item -ItemType Directory -Force -Path $LogDir | Out-Null
$ts  = Get-Date -Format 'yyyy-MM-dd_HHmmss'
$log = Join-Path $LogDir "setup_$ts.log"
function Log($m) {
    $line = '{0}  {1}' -f (Get-Date -Format 'HH:mm:ss'), $m
    Add-Content $log $line -Encoding UTF8
    Write-Host $line
}

Import-Module Posh-SSH
$cred = New-Object System.Management.Automation.PSCredential('root', (ConvertTo-SecureString $pwd -AsPlainText -Force))
$ssh  = New-SSHSession  -ComputerName $NewHost -Port 22 -Credential $cred -AcceptKey
$sftp = New-SFTPSession -ComputerName $NewHost -Port 22 -Credential $cred -AcceptKey

try {
    Log '=== Загрузка setup-db-and-nginx.sh ==='
    $bytes = [System.IO.File]::ReadAllText($ScriptLocal) -replace "`r`n","`n"
    $tmpLocal = Join-Path $LogDir "setup-lf-$ts.sh"
    [System.IO.File]::WriteAllText($tmpLocal, $bytes, (New-Object System.Text.UTF8Encoding $false))
    Set-SFTPItem -SessionId $sftp.SessionId -Path $tmpLocal -Destination /tmp -Force
    $remoteName = Split-Path $tmpLocal -Leaf
    $r = Invoke-SSHCommand -SessionId $ssh.SessionId -Command "mv /tmp/$remoteName /tmp/setup.sh && chmod +x /tmp/setup.sh && wc -l /tmp/setup.sh" -TimeOut 30
    Log ("Скрипт залит: " + ($r.Output -join ' '))
    Remove-Item $tmpLocal -Force -ErrorAction SilentlyContinue

    Log '=== Запуск ==='
    # Синхронно — операция короткая (минуты).
    # DB-креды передаём через env-vars в одной команде; bash экспортирует их
    # перед exec'ом скрипта. Кавычки одинарные — внутри пароля их быть не должно.
    $dbu = $dbVars['DB_USER']
    $dbp = $dbVars['DB_PASS']
    if ($dbp -match "'") { throw "DB_PASS содержит апостроф — поменяйте пароль или экранируйте вручную" }
    $cmd = "DB_USER='$dbu' DB_PASS='$dbp' bash /tmp/setup.sh 2>&1"
    $r = Invoke-SSHCommand -SessionId $ssh.SessionId -Command $cmd -TimeOut 600
    foreach ($ln in $r.Output) { Log "  $ln" }
    Log "ExitStatus=$($r.ExitStatus)"
}
finally {
    Remove-SFTPSession -SessionId $sftp.SessionId -ErrorAction SilentlyContinue | Out-Null
    Remove-SSHSession  -SessionId $ssh.SessionId  -ErrorAction SilentlyContinue | Out-Null
    Log "Лог: $log"
}
