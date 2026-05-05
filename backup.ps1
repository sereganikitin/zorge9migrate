#Requires -Version 5.1
<#
backup.ps1 — полный бэкап сайта с Timeweb по SFTP.
Читает креды из .env (рядом со скриптом), зеркалирует REMOTE_PATH в ./files/.
Использует модуль Posh-SSH (без admin-прав, только текущий пользователь).
#>
[CmdletBinding()]
param(
    [string]$EnvFile = (Join-Path $PSScriptRoot '.env'),
    [string]$DestDir = (Join-Path $PSScriptRoot 'files'),
    [string]$LogDir  = (Join-Path $PSScriptRoot 'logs')
)

$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

# --- 1. .env ---
if (-not (Test-Path $EnvFile)) {
    throw ".env не найден ($EnvFile). Скопируйте .env.example в .env и заполните."
}
$envVars = @{}
Get-Content $EnvFile -Encoding UTF8 | ForEach-Object {
    if ($_ -match '^\s*(#|$)') { return }
    if ($_ -match '^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$') {
        $envVars[$Matches[1]] = $Matches[2].Trim().Trim('"').Trim("'")
    }
}
foreach ($k in 'SFTP_HOST','SFTP_USER','SFTP_PASSWORD') {
    if (-not $envVars[$k]) { throw "В .env не задан $k" }
}
$port   = if ($envVars['SFTP_PORT']) { [int]$envVars['SFTP_PORT'] } else { 22 }
$remote = if ($envVars['REMOTE_PATH']) { $envVars['REMOTE_PATH'] } else { '.' }

# --- 2. Логи ---
New-Item -ItemType Directory -Force -Path $DestDir, $LogDir | Out-Null
$ts = Get-Date -Format 'yyyy-MM-dd_HHmmss'
$log = Join-Path $LogDir "backup_$ts.log"
function Log($msg) {
    $line = '{0}  {1}' -f (Get-Date -Format 'HH:mm:ss'), $msg
    Add-Content -Path $log -Value $line -Encoding UTF8
    Write-Host $line
}

Log '=== Timeweb SFTP backup ==='
Log ("Host  : {0}:{1}" -f $envVars['SFTP_HOST'], $port)
Log ("User  : {0}" -f $envVars['SFTP_USER'])
Log ("Remote: {0}" -f $remote)
Log ("Local : {0}" -f $DestDir)

# --- 3. Posh-SSH ---
if (-not (Get-Module -ListAvailable -Name Posh-SSH)) {
    throw "Модуль Posh-SSH не установлен. Запустите: Install-Module Posh-SSH -Scope CurrentUser -Force"
}
Import-Module Posh-SSH -ErrorAction Stop

# --- 4. Подключение ---
$sec  = ConvertTo-SecureString $envVars['SFTP_PASSWORD'] -AsPlainText -Force
$cred = New-Object System.Management.Automation.PSCredential($envVars['SFTP_USER'], $sec)

Log 'Подключаюсь...'
$session = New-SFTPSession -ComputerName $envVars['SFTP_HOST'] -Port $port -Credential $cred -AcceptKey -ErrorAction Stop
Log ("Подключено. SessionId={0}" -f $session.SessionId)

try {
    # Если REMOTE_PATH=. — определяем реальную домашнюю директорию.
    if ($remote -in @('.', '~', '')) {
        try { $remote = (Get-SFTPLocation -SessionId $session.SessionId).ToString() } catch { $remote = '.' }
        Log ("Resolved home: {0}" -f $remote)
    }

    Log ("Список верхнего уровня {0} :" -f $remote)
    $items = Get-SFTPChildItem -SessionId $session.SessionId -Path $remote
    foreach ($i in $items) {
        $kind = if ($i.IsDirectory) { '<DIR>' } else { '     ' }
        Log ("  {0}  {1}  {2}" -f $i.LastWriteTime, $kind, $i.FullName)
    }

    Log 'Качаю рекурсивно... (может занять много времени)'
    $sw = [System.Diagnostics.Stopwatch]::StartNew()
    Get-SFTPItem -SessionId $session.SessionId -Path $remote -Destination $DestDir -Force
    $sw.Stop()
    Log ("Готово за {0:N1} сек" -f $sw.Elapsed.TotalSeconds)
}
finally {
    Remove-SFTPSession -SessionId $session.SessionId | Out-Null
    Log 'Сессия закрыта.'
}

# --- 5. Итог ---
$files = Get-ChildItem $DestDir -Recurse -File -Force -ErrorAction SilentlyContinue
$count = ($files | Measure-Object).Count
$size  = ($files | Measure-Object -Sum Length).Sum
Log ("Скачано файлов: {0}, общий размер: {1:N1} МБ" -f $count, ($size/1MB))
Log ("Лог: {0}" -f $log)
