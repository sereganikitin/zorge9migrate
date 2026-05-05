#Requires -Version 5.1
<#
recon.ps1 — read-only разведка VDS перед бэкапом.
Подключается по SSH (Posh-SSH), выполняет диагностические команды,
сохраняет полный вывод в logs/recon_<timestamp>.txt.
НЕ изменяет ничего на сервере.
#>
[CmdletBinding()]
param(
    [string]$EnvFile = (Join-Path $PSScriptRoot '.env'),
    [string]$LogDir  = (Join-Path $PSScriptRoot 'logs')
)

$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

# --- .env ---
if (-not (Test-Path $EnvFile)) { throw ".env не найден ($EnvFile)" }
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
$port = if ($envVars['SFTP_PORT']) { [int]$envVars['SFTP_PORT'] } else { 22 }

New-Item -ItemType Directory -Force -Path $LogDir | Out-Null
$ts  = Get-Date -Format 'yyyy-MM-dd_HHmmss'
$out = Join-Path $LogDir "recon_$ts.txt"

Import-Module Posh-SSH -ErrorAction Stop
$sec  = ConvertTo-SecureString $envVars['SFTP_PASSWORD'] -AsPlainText -Force
$cred = New-Object System.Management.Automation.PSCredential($envVars['SFTP_USER'], $sec)

Write-Host "Connecting to $($envVars['SFTP_HOST']):$port ..."
$ssh = New-SSHSession -ComputerName $envVars['SFTP_HOST'] -Port $port -Credential $cred -AcceptKey -ErrorAction Stop
Write-Host "Connected. SessionId=$($ssh.SessionId). Saving recon to $out"

# Bash-команда: одна строка (через ; и &&), здесь — heredoc для читаемости.
# В лог пишем все секции с маркерами `=== <name> ===`, чтобы потом легко парсить.
$bash = @'
set +e
section() { echo; echo "=== $1 ==="; }
section "OS"; cat /etc/os-release 2>/dev/null; uname -a
section "uptime"; uptime
section "disk"; df -hT
section "mem"; free -h
section "users"; awk -F: '$3>=1000 || $3==0 {print $1, $3, $6, $7}' /etc/passwd
section "/var/www"; ls -la /var/www 2>/dev/null
section "/srv"; ls -la /srv 2>/dev/null
section "/srv/www"; ls -la /srv/www 2>/dev/null
section "/home"; ls -la /home 2>/dev/null
section "/opt"; ls -la /opt 2>/dev/null
section "nginx version"; nginx -v 2>&1
section "nginx sites-enabled"; ls -la /etc/nginx/sites-enabled 2>/dev/null
section "nginx conf.d"; ls -la /etc/nginx/conf.d 2>/dev/null
section "nginx -T (server_name + roots only)"; nginx -T 2>/dev/null | grep -E "server_name|root |listen |fastcgi_pass|proxy_pass|location " | head -200
section "apache2 version"; apache2 -v 2>&1; which httpd 2>&1
section "apache2 sites-enabled"; ls -la /etc/apache2/sites-enabled 2>/dev/null
section "apache2 -S"; apachectl -S 2>&1 | head -80
section "PHP versions installed"; ls -la /usr/bin/php* 2>/dev/null; ls /etc/php 2>/dev/null
section "PHP active"; php -v 2>&1; php -m 2>&1 | head -60
section "PHP-FPM pools"; ls /etc/php/*/fpm/pool.d 2>/dev/null
section "Node"; which node; node -v 2>&1; which pm2; pm2 list 2>&1 | head -40
section "MySQL/MariaDB version"; mysql --version 2>&1; mysqld --version 2>&1
section "MySQL my.cnf existence"; ls -la /root/.my.cnf 2>/dev/null && echo "(content hidden)" || echo "no /root/.my.cnf"; ls -la /etc/mysql/debian.cnf 2>/dev/null
section "MySQL databases"; mysql -e "SHOW DATABASES" 2>&1
section "MySQL data dir size"; du -sh /var/lib/mysql 2>/dev/null
section "Active services (filtered)"; systemctl list-units --type=service --state=running --no-legend --no-pager 2>/dev/null | grep -Ei "nginx|apache|php|mysql|mariadb|postgres|redis|memcache|node|pm2|docker|gunicorn|uwsgi|supervisor" | head -40
section "Listening ports"; ss -tlnp 2>/dev/null | head -30
section "Docker"; which docker; docker ps -a 2>&1 | head -40
section "crontab root"; crontab -l 2>&1
section "/etc/cron.*"; ls -la /etc/cron.d /etc/cron.daily /etc/cron.hourly /etc/cron.weekly 2>/dev/null | head -80
section "find zorge"; find / -maxdepth 5 -iname "*zorge*" -not -path "/proc/*" -not -path "/sys/*" -not -path "/root/.bash_history" 2>/dev/null | head -80
section "site dir sizes"; for d in /var/www/* /srv/www/* /home/*; do [ -d "$d" ] && du -sh "$d" 2>/dev/null; done
section "git repos in www"; find /var/www /srv/www /home /opt -maxdepth 4 -name ".git" -type d 2>/dev/null | head -40
section "letsencrypt certs"; ls /etc/letsencrypt/live 2>/dev/null
section "Done"
'@

$res = Invoke-SSHCommand -SessionId $ssh.SessionId -Command $bash -TimeOut 120
$res.Output | Out-File -FilePath $out -Encoding UTF8
Remove-SSHSession -SessionId $ssh.SessionId | Out-Null

Write-Host ""
Write-Host "=== Краткий итог разведки ==="
$txt = Get-Content $out -Raw
$summary = @()
foreach ($pat in 'OS','/var/www','nginx sites-enabled','PHP active','MySQL databases','find zorge','site dir sizes','letsencrypt certs') {
    if ($txt -match "(?ms)=== $([regex]::Escape($pat)) ===\s*\r?\n(.+?)(?:\r?\n=== |\Z)") {
        $body = $Matches[1].Trim()
        if ($body.Length -gt 600) { $body = $body.Substring(0,600) + "`n... (cut)" }
        $summary += "--- $pat ---`n$body`n"
    }
}
$summary -join "`n"
Write-Host ""
Write-Host "Полный лог разведки: $out"
