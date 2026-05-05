#Requires -Version 7.0
<#
recon-new.ps1 — read-only осмотр свежего VDS на 178.253.42.235.
Пароль читается из .env-new (raw, одна строка).
#>
param(
    [string]$EnvFile = (Join-Path $PSScriptRoot '.env-new'),
    [string]$NewHost = '178.253.42.235',
    [int]   $NewPort = 22,
    [string]$NewUser = 'root',
    [string]$LogDir  = (Join-Path $PSScriptRoot 'logs')
)
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

# raw password (одна строка)
$pwd = (Get-Content $EnvFile -Raw -Encoding UTF8).Trim()
if (-not $pwd) { throw "Пустой .env-new" }

New-Item -ItemType Directory -Force -Path $LogDir | Out-Null
$ts  = Get-Date -Format 'yyyy-MM-dd_HHmmss'
$out = Join-Path $LogDir "recon-new_$ts.txt"

Import-Module Posh-SSH
$cred = New-Object System.Management.Automation.PSCredential($NewUser, (ConvertTo-SecureString $pwd -AsPlainText -Force))
Write-Host "Connecting to $NewHost ..."
$ssh = New-SSHSession -ComputerName $NewHost -Port $NewPort -Credential $cred -AcceptKey -ErrorAction Stop
Write-Host "Connected. SessionId=$($ssh.SessionId). Saving recon to $out"

$bash = @'
section() { echo; echo "=== $1 ==="; }
section "OS"; cat /etc/os-release; uname -a
section "uptime/load"; uptime
section "disk"; df -hT /
section "memory"; free -h
section "cpu"; nproc; lscpu | grep -E "Model name|CPU\(s\)|Architecture" | head -5
section "network"; hostname -I; ip -4 route show default
section "users"; awk -F: '$3>=1000 || $3==0 {print $1, $3, $6, $7}' /etc/passwd
section "installed web stack (if any)"; which nginx apache2 caddy 2>/dev/null; nginx -v 2>&1; apache2 -v 2>&1 | head -1
section "installed php"; ls /usr/bin/php* 2>/dev/null; which php; php -v 2>&1 | head -3
section "installed mysql/mariadb"; which mysql mysqld mariadb 2>/dev/null; mysql --version 2>&1; systemctl list-units --type=service --state=running --no-legend 2>/dev/null | grep -E "mysql|mariadb|nginx|apache|php|fpm"
section "ufw status"; ufw status verbose 2>&1 | head -20
section "available repos for php"; apt-cache policy php7.4-fpm 2>&1 | head -5
section "free /tmp"; df -h /tmp
section "PPA / sury check"; ls /etc/apt/sources.list.d/ 2>/dev/null
section "running services"; systemctl list-units --type=service --state=running --no-legend --no-pager 2>/dev/null | head -30
section "Done"
'@
$res = Invoke-SSHCommand -SessionId $ssh.SessionId -Command $bash -TimeOut 60
$res.Output | Out-File -FilePath $out -Encoding UTF8
Remove-SSHSession -SessionId $ssh.SessionId | Out-Null

Write-Host ""
Write-Host "=== Сводка нового сервера ==="
$txt = Get-Content $out -Raw
foreach ($pat in 'OS','disk','memory','cpu','network','installed web stack (if any)','installed php','installed mysql/mariadb','ufw status','available repos for php','PPA / sury check') {
    if ($txt -match "(?ms)=== $([regex]::Escape($pat)) ===\s*\r?\n(.+?)(?:\r?\n=== |\Z)") {
        $body = $Matches[1].Trim()
        if ($body.Length -gt 500) { $body = $body.Substring(0,500) + "`n... (cut)" }
        Write-Host "--- $pat ---"
        Write-Host $body
        Write-Host ""
    }
}
Write-Host "Полный лог: $out"
