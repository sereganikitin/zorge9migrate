#Requires -Version 5.1
<#
dump.ps1 — снимает дамп БД и конфигов с VDS:
 1) mysqldump zorge9 и feedbacks (gzip)
 2) tar конфигов: nginx, php, letsencrypt, cron, systemd, hosts/hostname
 3) служебные списки: crontab root, dpkg -l, services
 4) скачивает всё в local db/ и распаковывает configs.tar.gz в configs/
 5) удаляет /tmp/zorge9_dump на сервере
#>
[CmdletBinding()]
param(
    [string]$EnvFile = (Join-Path $PSScriptRoot '.env'),
    [string]$LogDir  = (Join-Path $PSScriptRoot 'logs'),
    [string]$DbDir   = (Join-Path $PSScriptRoot 'db'),
    [string]$CfgDir  = (Join-Path $PSScriptRoot 'configs')
)
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

# --- env ---
$envVars = @{}
Get-Content $EnvFile -Encoding UTF8 | ForEach-Object {
    if ($_ -match '^\s*(#|$)') { return }
    if ($_ -match '^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$') { $envVars[$Matches[1]] = $Matches[2].Trim().Trim('"').Trim("'") }
}
$port = if ($envVars['SFTP_PORT']) { [int]$envVars['SFTP_PORT'] } else { 22 }

New-Item -ItemType Directory -Force -Path $LogDir, $DbDir, $CfgDir | Out-Null
$ts  = Get-Date -Format 'yyyy-MM-dd_HHmmss'
$log = Join-Path $LogDir "dump_$ts.log"
function Log($msg) {
    $line = '{0}  {1}' -f (Get-Date -Format 'HH:mm:ss'), $msg
    Add-Content -Path $log -Value $line -Encoding UTF8
    Write-Host $line
}

Import-Module Posh-SSH
$cred = New-Object System.Management.Automation.PSCredential($envVars['SFTP_USER'], (ConvertTo-SecureString $envVars['SFTP_PASSWORD'] -AsPlainText -Force))

Log '=== Подключение SSH+SFTP ==='
$ssh  = New-SSHSession  -ComputerName $envVars['SFTP_HOST'] -Port $port -Credential $cred -AcceptKey -ErrorAction Stop
$sftp = New-SFTPSession -ComputerName $envVars['SFTP_HOST'] -Port $port -Credential $cred -AcceptKey -ErrorAction Stop
Log "SSH SessionId=$($ssh.SessionId), SFTP SessionId=$($sftp.SessionId)"

# --- 1+2+3: серверный bash: dump + configs + lists ---
$bash = @'
set +e
DUMPDIR=/tmp/zorge9_dump
rm -rf "$DUMPDIR"
mkdir -p "$DUMPDIR"
cd "$DUMPDIR" || exit 1

echo "=== mysqldump: zorge9 ==="
mysqldump --single-transaction --quick --routines --triggers --events --hex-blob \
  --default-character-set=utf8mb4 zorge9 2> zorge9.err | gzip -9 > zorge9.sql.gz
zsz=$(stat -c%s zorge9.sql.gz 2>/dev/null)
echo "zorge9.sql.gz: $zsz bytes"
if [ -s zorge9.err ]; then echo "[zorge9 stderr]:"; head -20 zorge9.err; fi

echo "=== mysqldump: feedbacks ==="
mysqldump --single-transaction --quick --routines --triggers --events --hex-blob \
  --default-character-set=utf8mb4 feedbacks 2> feedbacks.err | gzip -9 > feedbacks.sql.gz
fsz=$(stat -c%s feedbacks.sql.gz 2>/dev/null)
echo "feedbacks.sql.gz: $fsz bytes"
if [ -s feedbacks.err ]; then echo "[feedbacks stderr]:"; head -20 feedbacks.err; fi

echo "=== tar: configs ==="
tar -czf configs.tar.gz \
  --warning=no-file-changed --ignore-failed-read \
  /etc/nginx \
  /etc/php \
  /etc/letsencrypt \
  /etc/cron.d /etc/cron.daily /etc/cron.hourly /etc/cron.weekly /etc/cron.monthly \
  /var/spool/cron \
  /etc/systemd/system \
  /etc/hosts /etc/hostname /etc/timezone \
  2> configs.err
csz=$(stat -c%s configs.tar.gz 2>/dev/null)
echo "configs.tar.gz: $csz bytes"
if [ -s configs.err ]; then echo "[configs stderr first 20]:"; head -20 configs.err; fi

echo "=== service info ==="
crontab -l > crontab-root.txt 2>/dev/null
dpkg -l > dpkg-list.txt 2>/dev/null
systemctl list-unit-files --type=service > services.txt 2>/dev/null
ls -laR /etc/letsencrypt/live > letsencrypt-live.txt 2>/dev/null

echo "=== итог ==="
ls -lah "$DUMPDIR"
'@

Log '=== Шаг 1+2+3: дампы и конфиги на сервере ==='
$res = Invoke-SSHCommand -SessionId $ssh.SessionId -Command $bash -TimeOut 1800
$res.Output | ForEach-Object { Log "  $_" }
if ($res.ExitStatus -ne 0) { Log "ВНИМАНИЕ: серверная часть вернула ExitStatus=$($res.ExitStatus)" }

# --- 4: скачивание /tmp/zorge9_dump → ./db/zorge9_dump ---
Log ''
Log '=== Шаг 4: скачивание архивов в db/ ==='
$sw = [System.Diagnostics.Stopwatch]::StartNew()
Get-SFTPItem -SessionId $sftp.SessionId -Path '/tmp/zorge9_dump' -Destination $DbDir -Force
$sw.Stop()
Log ("Скачано за {0:N1} сек" -f $sw.Elapsed.TotalSeconds)

$localDump = Join-Path $DbDir 'zorge9_dump'
Log "Содержимое $localDump :"
Get-ChildItem $localDump -Force | ForEach-Object {
    Log ("  {0,12} {1}" -f $_.Length, $_.Name)
}

# распаковываем configs.tar.gz в configs/
$cfgArchive = Join-Path $localDump 'configs.tar.gz'
if (Test-Path $cfgArchive) {
    Log ''
    Log "=== Распаковка configs.tar.gz в $CfgDir ==="
    # tar есть в Windows 10/11 встроенный (bsdtar)
    Push-Location $CfgDir
    & tar -xzf $cfgArchive
    Pop-Location
    Log "Готово. Топ-уровень configs/:"
    Get-ChildItem $CfgDir -Force | Select-Object Name | ForEach-Object { Log ("  {0}" -f $_.Name) }
} else {
    Log "configs.tar.gz отсутствует — пропускаю распаковку"
}

# --- 5: уборка на сервере ---
Log ''
Log '=== Шаг 5: удаление /tmp/zorge9_dump на сервере ==='
$rm = Invoke-SSHCommand -SessionId $ssh.SessionId -Command 'rm -rf /tmp/zorge9_dump && echo cleaned' -TimeOut 30
$rm.Output | ForEach-Object { Log "  $_" }

Remove-SFTPSession -SessionId $sftp.SessionId | Out-Null
Remove-SSHSession  -SessionId $ssh.SessionId  | Out-Null
Log 'Сессии закрыты.'
Log "Лог: $log"
