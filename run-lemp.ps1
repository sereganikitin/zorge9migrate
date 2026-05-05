#Requires -Version 7.0
<#
run-lemp.ps1 — заливает install-lemp.sh на новый сервер, запускает в nohup,
поллит прогресс по стадиям. Уведомляет о завершении.
#>
[CmdletBinding()]
param(
    [string]$EnvNew      = (Join-Path $PSScriptRoot '.env-new'),
    [string]$ScriptLocal = (Join-Path $PSScriptRoot 'install-lemp.sh'),
    [string]$NewHost     = '178.253.42.235',
    [int]   $NewPort     = 22,
    [string]$LogDir      = (Join-Path $PSScriptRoot 'logs'),
    [int]   $PollSec     = 15
)
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$pwd = (Get-Content $EnvNew -Raw -Encoding UTF8).Trim()
New-Item -ItemType Directory -Force -Path $LogDir | Out-Null
$ts  = Get-Date -Format 'yyyy-MM-dd_HHmmss'
$log = Join-Path $LogDir "lemp_$ts.log"
function Log($m) {
    $line = '{0}  {1}' -f (Get-Date -Format 'HH:mm:ss'), $m
    Add-Content $log $line -Encoding UTF8
    Write-Host $line
}

Import-Module Posh-SSH
$cred = New-Object System.Management.Automation.PSCredential('root', (ConvertTo-SecureString $pwd -AsPlainText -Force))

Log '=== Подключение к новому серверу ==='
$ssh  = New-SSHSession  -ComputerName $NewHost -Port $NewPort -Credential $cred -AcceptKey -ErrorAction Stop
$sftp = New-SFTPSession -ComputerName $NewHost -Port $NewPort -Credential $cred -AcceptKey -ErrorAction Stop

try {
    # --- 1. Заливаем скрипт через SFTP, нормализуя CRLF -> LF ---
    Log '=== Загрузка install-lemp.sh ==='
    $bytes = [System.IO.File]::ReadAllText($ScriptLocal) -replace "`r`n","`n"
    $tmpLocal = Join-Path $LogDir "install-lemp-lf-$ts.sh"
    [System.IO.File]::WriteAllText($tmpLocal, $bytes, (New-Object System.Text.UTF8Encoding $false))
    Set-SFTPItem -SessionId $sftp.SessionId -Path $tmpLocal -Destination /tmp -Force
    $remoteName = Split-Path $tmpLocal -Leaf
    $r = Invoke-SSHCommand -SessionId $ssh.SessionId -Command "mv /tmp/$remoteName /tmp/install.sh && chmod +x /tmp/install.sh && wc -l /tmp/install.sh" -TimeOut 30
    Log ("Скрипт залит: " + ($r.Output -join ' '))
    Remove-Item $tmpLocal -Force -ErrorAction SilentlyContinue

    # --- 2. Запуск в nohup ---
    Log '=== Запуск установки в фоне ==='
    $launch = @'
rm -f /tmp/install.log /tmp/install.done
nohup bash -c '
  bash /tmp/install.sh
  EC=$?
  echo "EXIT_CODE=$EC" > /tmp/install.done
  date >> /tmp/install.done
' > /tmp/install.log 2>&1 &
echo "PID=$!"
'@
    $r = Invoke-SSHCommand -SessionId $ssh.SessionId -Command $launch -TimeOut 30
    $pidLine = ($r.Output | Where-Object { $_ -like 'PID=*' } | Select-Object -First 1)
    Log "Запущен: $pidLine"

    # --- 3. Поллинг ---
    Log "=== Поллинг каждые $PollSec сек ==="
    $sw = [System.Diagnostics.Stopwatch]::StartNew()
    $lastStage = ''
    while ($true) {
        Start-Sleep -Seconds $PollSec
        $r = Invoke-SSHCommand -SessionId $ssh.SessionId -Command @'
echo '---STAGES---'
grep -a "^=== STAGE\|^=== DONE" /tmp/install.log 2>/dev/null | tail -3
echo '---DONE---'
[ -f /tmp/install.done ] && cat /tmp/install.done
echo '---LOGSIZE---'
wc -l /tmp/install.log 2>/dev/null
'@ -TimeOut 30
        $output = $r.Output -join "`n"
        $stages = $r.Output | Where-Object { $_ -match '^=== (STAGE|DONE)' }
        $current = if ($stages) { $stages[-1] } else { '(no stage yet)' }
        $lines   = ($r.Output | Where-Object { $_ -match '\d+\s+/tmp/install.log' } | Select-Object -First 1)
        Log ("[{0}]  {1}  log_lines={2}" -f $sw.Elapsed.ToString('hh\:mm\:ss'), $current, $lines)
        if ($current -ne $lastStage) { $lastStage = $current }

        if ($output -match 'EXIT_CODE=(\d+)') {
            $ec = [int]$Matches[1]
            Log "ЗАВЕРШЕНО, EXIT_CODE=$ec"
            $tailRes = Invoke-SSHCommand -SessionId $ssh.SessionId -Command 'tail -50 /tmp/install.log' -TimeOut 30
            Log "--- хвост лога ---"
            foreach ($ln in $tailRes.Output) { Log "  $ln" }
            if ($ec -ne 0) { Log "ВНИМАНИЕ: установка вернула ненулевой код" }
            break
        }
    }
}
finally {
    Remove-SFTPSession -SessionId $sftp.SessionId -ErrorAction SilentlyContinue | Out-Null
    Remove-SSHSession  -SessionId $ssh.SessionId  -ErrorAction SilentlyContinue | Out-Null
    Log "Лог: $log"
}
