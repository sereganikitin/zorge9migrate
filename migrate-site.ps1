#Requires -Version 7.0
<#
migrate-site.ps1 — server-to-server stream бэкап сайта.

Идея: на новом сервере генерируется одноразовый ed25519 ключ.
Публичный ключ временно добавляется в /root/.ssh/authorized_keys старого сервера.
На новом запускается nohup `ssh OLD "tar -cf -" | tar -xf -`, данные летят
напрямую между датацентрами. PowerShell поллит прогресс. По завершении —
ключ удаляется со старого сервера, локальные ключи стираются.
#>
[CmdletBinding()]
param(
    [string]$EnvOld    = (Join-Path $PSScriptRoot '.env'),
    [string]$EnvNew    = (Join-Path $PSScriptRoot '.env-new'),
    [string]$OldHost   = '94.228.116.219',
    [string]$NewHost   = '178.253.42.235',
    [int]   $OldPort   = 22,
    [int]   $NewPort   = 22,
    [string]$OldUser   = 'root',
    [string]$NewUser   = 'root',
    [string]$LogDir    = (Join-Path $PSScriptRoot 'logs'),
    [int]   $PollSec   = 30
)
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

# --- читаем пароли ---
$oldVars = @{}
Get-Content $EnvOld -Encoding UTF8 | ForEach-Object {
    if ($_ -match '^\s*(#|$)') { return }
    if ($_ -match '^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$') { $oldVars[$Matches[1]] = $Matches[2].Trim().Trim('"').Trim("'") }
}
$oldPwd = $oldVars['SFTP_PASSWORD']
$newPwd = (Get-Content $EnvNew -Raw -Encoding UTF8).Trim()
if (-not $oldPwd) { throw "Нет SFTP_PASSWORD в $EnvOld" }
if (-not $newPwd) { throw "Пустой $EnvNew" }

New-Item -ItemType Directory -Force -Path $LogDir | Out-Null
$ts  = Get-Date -Format 'yyyy-MM-dd_HHmmss'
$log = Join-Path $LogDir "migrate_$ts.log"
function Log($m) {
    $line = '{0}  {1}' -f (Get-Date -Format 'HH:mm:ss'), $m
    Add-Content $log $line -Encoding UTF8
    Write-Host $line
}

Import-Module Posh-SSH
$oldCred = New-Object System.Management.Automation.PSCredential($OldUser, (ConvertTo-SecureString $oldPwd -AsPlainText -Force))
$newCred = New-Object System.Management.Automation.PSCredential($NewUser, (ConvertTo-SecureString $newPwd -AsPlainText -Force))

Log '=== Подключение к обоим серверам ==='
$newSsh = New-SSHSession -ComputerName $NewHost -Port $NewPort -Credential $newCred -AcceptKey -ErrorAction Stop
$oldSsh = New-SSHSession -ComputerName $OldHost -Port $OldPort -Credential $oldCred -AcceptKey -ErrorAction Stop
Log ("OLD SSH={0}, NEW SSH={1}" -f $oldSsh.SessionId, $newSsh.SessionId)

try {
    # --- 1. Сгенерировать одноразовый ключ на NEW ---
    Log '=== Генерация ed25519 ключа на новом сервере ==='
    $genCmd = @'
mkdir -p /root/.ssh && chmod 700 /root/.ssh
rm -f /root/.ssh/zorge9_migration_key /root/.ssh/zorge9_migration_key.pub
ssh-keygen -t ed25519 -f /root/.ssh/zorge9_migration_key -N '' -q -C zorge9-migration
echo '---PUBKEY---'
cat /root/.ssh/zorge9_migration_key.pub
'@
    $r = Invoke-SSHCommand -SessionId $newSsh.SessionId -Command $genCmd -TimeOut 30
    $pubkey = ($r.Output | Where-Object { $_ -like 'ssh-ed25519*zorge9-migration*' } | Select-Object -First 1)
    if (-not $pubkey) { throw "Не удалось получить публичный ключ:`n$($r.Output -join "`n")" }
    Log "Pubkey получен (длина $($pubkey.Length))"

    # --- 2. Прописать ключ на OLD ---
    Log '=== Установка временного ключа на старом сервере ==='
    # Single-quoted для bash, экранируем апострофы — в ed25519 их не бывает.
    $addKey = "mkdir -p /root/.ssh && chmod 700 /root/.ssh && touch /root/.ssh/authorized_keys && chmod 600 /root/.ssh/authorized_keys && grep -q 'zorge9-migration' /root/.ssh/authorized_keys || echo '$pubkey' >> /root/.ssh/authorized_keys && echo OK"
    $r = Invoke-SSHCommand -SessionId $oldSsh.SessionId -Command $addKey -TimeOut 30
    if (($r.Output -join '') -notmatch 'OK') { throw "Не удалось добавить ключ на OLD:`n$($r.Output -join "`n")" }
    Log "Ключ добавлен в /root/.ssh/authorized_keys на старом."

    # --- 3. Тест: SSH с NEW на OLD без пароля ---
    Log '=== Тест ключевой авторизации NEW -> OLD ==='
    $testCmd = "ssh -i /root/.ssh/zorge9_migration_key -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile=/root/.ssh/zorge9_migration_known -o BatchMode=yes -o ConnectTimeout=15 root@$OldHost 'echo TEST_OK && uname -n'"
    $r = Invoke-SSHCommand -SessionId $newSsh.SessionId -Command $testCmd -TimeOut 30
    if (($r.Output -join "`n") -notmatch 'TEST_OK') { throw "Тест ключевой авторизации НЕ прошёл:`n$($r.Output -join "`n")" }
    Log ("Тест ОК. Ответ старого сервера: " + (($r.Output -join "`n") -replace "TEST_OK\s*",''))

    # --- 4. Запуск трансфера в nohup ---
    Log '=== Запуск streaming tar в фоне на новом сервере ==='
    $launch = @"
set -e
mkdir -p /var/www
rm -rf /var/www/old.zorge9.com
rm -f /tmp/zorge9_transfer.done /tmp/zorge9_transfer.log
cd /var/www
nohup bash -c '
  set -o pipefail
  ssh -i /root/.ssh/zorge9_migration_key \
      -o StrictHostKeyChecking=no \
      -o UserKnownHostsFile=/root/.ssh/zorge9_migration_known \
      -o ServerAliveInterval=30 \
      -o BatchMode=yes \
      root@$OldHost \
      "tar -cf - --warning=no-file-changed --exclude=.git -C /var/www old.zorge9.com" \
    | tar -xf - -C /var/www --no-same-owner
  EC=`$?
  echo "EXIT_CODE=`$EC" > /tmp/zorge9_transfer.done
  date >> /tmp/zorge9_transfer.done
' > /tmp/zorge9_transfer.log 2>&1 &
echo "PID=`$!"
"@
    $r = Invoke-SSHCommand -SessionId $newSsh.SessionId -Command $launch -TimeOut 30
    $pidLine = ($r.Output | Where-Object { $_ -like 'PID=*' } | Select-Object -First 1)
    Log "Transfer запущен: $pidLine"

    # --- 5. Поллинг ---
    Log "=== Поллинг прогресса каждые $PollSec сек ==="
    $sw = [System.Diagnostics.Stopwatch]::StartNew()
    while ($true) {
        Start-Sleep -Seconds $PollSec
        $poll = @'
du -sh /var/www/old.zorge9.com 2>/dev/null
echo "---"
[ -f /tmp/zorge9_transfer.done ] && cat /tmp/zorge9_transfer.done
'@
        $r = Invoke-SSHCommand -SessionId $newSsh.SessionId -Command $poll -TimeOut 60
        $sizeLine = ($r.Output | Where-Object { $_ -match '/var/www/old\.zorge9\.com\s*$' } | Select-Object -First 1)
        $exitLine = ($r.Output | Where-Object { $_ -like 'EXIT_CODE=*' } | Select-Object -First 1)
        Log ("[{0}]  size: {1}" -f $sw.Elapsed.ToString('hh\:mm\:ss'), $sizeLine)
        if ($exitLine) {
            Log "ЗАВЕРШЕНО: $exitLine"
            $tailRes = Invoke-SSHCommand -SessionId $newSsh.SessionId -Command 'tail -10 /tmp/zorge9_transfer.log' -TimeOut 30
            Log "Хвост лога:"; foreach ($ln in $tailRes.Output) { Log "  $ln" }
            break
        }
    }
}
finally {
    # --- 6. Уборка: удалить ключ со старого ---
    Log '=== Уборка: удаление временного ключа со старого сервера ==='
    try {
        $clean = "sed -i '/zorge9-migration/d' /root/.ssh/authorized_keys && echo CLEANED_OLD"
        $r = Invoke-SSHCommand -SessionId $oldSsh.SessionId -Command $clean -TimeOut 30
        Log ($r.Output -join ' ')
    } catch { Log "WARN: cleanup OLD failed: $($_.Exception.Message)" }

    Log '=== Уборка: удаление ключа на новом сервере ==='
    try {
        $clean = "rm -f /root/.ssh/zorge9_migration_key /root/.ssh/zorge9_migration_key.pub /root/.ssh/zorge9_migration_known /tmp/zorge9_transfer.done /tmp/zorge9_transfer.log && echo CLEANED_NEW"
        $r = Invoke-SSHCommand -SessionId $newSsh.SessionId -Command $clean -TimeOut 30
        Log ($r.Output -join ' ')
    } catch { Log "WARN: cleanup NEW failed: $($_.Exception.Message)" }

    Remove-SSHSession -SessionId $oldSsh.SessionId -ErrorAction SilentlyContinue | Out-Null
    Remove-SSHSession -SessionId $newSsh.SessionId -ErrorAction SilentlyContinue | Out-Null
    Log "Сессии закрыты. Лог: $log"
}
