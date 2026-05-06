#Requires -Version 7.0
<#
diagnose-admin.ps1 — read-only диагностика "после миграции админка 404".

Подключается по SSH (Posh-SSH) к новому серверу, собирает:
  - текущий nginx-конфиг (sites-enabled, conf.d)
  - htdocs/.htaccess и Wolf CMS config.php
  - settings из БД zorge9 с упоминанием http/url
  - права/владельца htdocs/admin (если папка), htdocs/wolf
  - sess.save_path и его доступность
  - последние строки nginx/php-fpm error log
  - живой curl на /admin, /admin/login, /admin/login/login через 127.0.0.1
    с Host: zorge9.infoseledka.ru — чтобы увидеть что реально отвечает PHP

НЕ изменяет ничего на сервере.
#>
[CmdletBinding()]
param(
    [string]$EnvNew  = (Join-Path $PSScriptRoot '.env-new'),
    [string]$NewHost = '178.253.42.235',
    [int]   $NewPort = 22,
    [string]$NewUser = 'root',
    [string]$Domain  = 'zorge9.infoseledka.ru',
    [string]$LogDir  = (Join-Path $PSScriptRoot 'logs')
)
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

if (-not (Test-Path $EnvNew)) { throw "$EnvNew не найден" }
$pwd = (Get-Content $EnvNew -Raw -Encoding UTF8).Trim()
if (-not $pwd) { throw "Пустой $EnvNew" }

New-Item -ItemType Directory -Force -Path $LogDir | Out-Null
$ts  = Get-Date -Format 'yyyy-MM-dd_HHmmss'
$out = Join-Path $LogDir "diagnose-admin_$ts.txt"

Import-Module Posh-SSH -ErrorAction Stop
$cred = New-Object System.Management.Automation.PSCredential($NewUser, (ConvertTo-SecureString $pwd -AsPlainText -Force))

Write-Host "Connecting to ${NewHost}:${NewPort} ..."
$ssh = New-SSHSession -ComputerName $NewHost -Port $NewPort -Credential $cred -AcceptKey -ErrorAction Stop
Write-Host "Connected. SessionId=$($ssh.SessionId). Output -> $out"

# Собираем диагностику одной bash-командой. set +e чтобы продолжать при ошибках.
$bash = @"
set +e
section() { echo; echo "=== `$1 ==="; }

DOMAIN='$Domain'
ROOT=/var/www/old.zorge9.com/htdocs

section "host info"
hostname; date; uname -a

section "nginx -v"
nginx -v 2>&1

section "nginx -T (server_name + listen + root + location + rewrite)"
nginx -T 2>/dev/null | grep -nE 'server_name|^\s*listen |^\s*root |location |rewrite |try_files|fastcgi_pass' | head -200

section "nginx sites-enabled list"
ls -la /etc/nginx/sites-enabled 2>/dev/null

section "FULL nginx config: sites-available/old.zorge9.com"
cat /etc/nginx/sites-available/old.zorge9.com 2>/dev/null

section "FULL nginx config: any other site files"
for f in /etc/nginx/sites-enabled/* /etc/nginx/conf.d/*.conf; do
  [ -f "`$f" ] || continue
  echo "--- `$f ---"
  cat "`$f"
done

section "htdocs root listing"
ls -la `$ROOT 2>/dev/null | head -40

section "htdocs/admin (file? dir? exists?)"
ls -ld `$ROOT/admin 2>/dev/null
ls -la `$ROOT/admin 2>/dev/null | head -20

section "htdocs/wolf listing (top)"
ls -la `$ROOT/wolf 2>/dev/null | head -30

section "htdocs/wolf/admin listing"
ls -la `$ROOT/wolf/admin 2>/dev/null | head -30

section ".htaccess (root) — что было на Apache"
cat `$ROOT/.htaccess 2>/dev/null

section ".htaccess (wolf, admin)"
for f in `$ROOT/wolf/.htaccess `$ROOT/wolf/admin/.htaccess `$ROOT/admin/.htaccess; do
  [ -f "`$f" ] || continue
  echo "--- `$f ---"
  cat "`$f"
done

section "config.php — Wolf CMS"
# показываем без чувствительных значений (пароль БД маскируем)
grep -nE 'define|URL_PUBLIC|URL_SUFFIX|BASE_URL|USE_MOD_REWRITE|SECRET|DEBUG|TBL_PREFIX|DB_' `$ROOT/config.php 2>/dev/null | sed -E "s/(DB_PASS|PASSWORD|SECRET)([^a-zA-Z0-9_]*=[^,;]*['\"])([^'\"]+)/\1\2***/g"

section "Wolf CMS settings таблица — все http*-значения"
mysql zorge9 -N -e "SELECT name, value FROM settings WHERE value LIKE 'http%' OR name IN ('base_url','site_url','admin_url','url_suffix','use_mod_rewrite','default_tab','language');" 2>&1 | head -60

section "PHP сессии: save_path и доступ"
php -r "echo 'session.save_path=', ini_get('session.save_path'), \"\n\"; echo 'session.cookie_domain=', ini_get('session.cookie_domain'), \"\n\"; echo 'session.cookie_secure=', ini_get('session.cookie_secure'), \"\n\";"
SP=`$(php -r "echo ini_get('session.save_path') ?: '/var/lib/php/sessions';")
echo "checking `$SP"
ls -ld "`$SP" 2>/dev/null
sudo -u www-data test -w "`$SP" && echo "writable by www-data: YES" || echo "writable by www-data: NO"

section "ownership / perms по подозрительным путям"
stat -c '%a %U:%G %n' `$ROOT `$ROOT/index.php `$ROOT/config.php `$ROOT/wolf 2>/dev/null
[ -e `$ROOT/admin ] && stat -c '%a %U:%G %n' `$ROOT/admin

section "live curl: home"
curl -ski -H "Host: `$DOMAIN" "http://127.0.0.1/" -o /tmp/d_home.html -w "HTTP %{http_code} ct=%{content_type} size=%{size_download}\n" | tail -3
echo "--- response head ---"; head -c 300 /tmp/d_home.html; echo

section "live curl: /admin (без слэша)"
curl -ski -H "Host: `$DOMAIN" "http://127.0.0.1/admin" -o /tmp/d_admin.html -w "HTTP %{http_code} ct=%{content_type} size=%{size_download}\n" | tail -3
echo "--- headers ---"
curl -skI -H "Host: `$DOMAIN" "http://127.0.0.1/admin" | head -15
echo "--- response head ---"; head -c 500 /tmp/d_admin.html; echo

section "live curl: /admin/login"
curl -ski -H "Host: `$DOMAIN" "http://127.0.0.1/admin/login" -o /tmp/d_login.html -w "HTTP %{http_code} ct=%{content_type} size=%{size_download}\n" | tail -3
echo "--- headers ---"
curl -skI -H "Host: `$DOMAIN" "http://127.0.0.1/admin/login" | head -15
echo "--- response head ---"; head -c 800 /tmp/d_login.html; echo

section "live curl: /admin/login/login (тот URL что 404)"
curl -ski -H "Host: `$DOMAIN" "http://127.0.0.1/admin/login/login" -o /tmp/d_loginlogin.html -w "HTTP %{http_code} ct=%{content_type} size=%{size_download}\n" | tail -3
echo "--- headers ---"
curl -skI -H "Host: `$DOMAIN" "http://127.0.0.1/admin/login/login" | head -15
echo "--- response head ---"; head -c 800 /tmp/d_loginlogin.html; echo

section "что прилетает в PHP: SCRIPT_FILENAME / WOLFPAGE для /admin/login/login"
# симулируем что nginx передаёт fastcgi_param-ы:
echo "Запрос /admin/login/login с текущим nginx-конфигом отправит в php-fpm:"
echo "  REQUEST_URI=/admin/login/login"
echo "  и при try_files-fallback — index.php с QUERY_STRING зависящим от location /"
grep -nE 'try_files|WOLFPAGE|rewrite ' /etc/nginx/sites-available/old.zorge9.com 2>/dev/null

section "tail nginx access (последние 15 строк, фильтруем admin/login)"
tail -200 /var/log/nginx/old.zorge9.com-access.log 2>/dev/null | grep -E 'admin|login' | tail -15

section "tail nginx error (15 строк)"
tail -15 /var/log/nginx/old.zorge9.com-error.log 2>/dev/null

section "tail php-fpm error (15 строк)"
tail -15 /var/log/php8.3-fpm.log 2>/dev/null

section "tail PHP error log если есть в htdocs"
find `$ROOT -maxdepth 3 -name 'error_log' -o -name 'php-error.log' 2>/dev/null | head -3 | while read f; do
  echo "--- `$f ---"; tail -15 "`$f"
done

section "Wolf CMS логи"
find `$ROOT -maxdepth 4 -path '*/wolf/log*' -o -path '*/wolf/cache/log*' 2>/dev/null | head -10
tail -15 `$ROOT/wolf/log/wolf.log 2>/dev/null

section "DONE"
"@

$res = Invoke-SSHCommand -SessionId $ssh.SessionId -Command $bash -TimeOut 180
$res.Output | Out-File -FilePath $out -Encoding UTF8
Remove-SSHSession -SessionId $ssh.SessionId | Out-Null

Write-Host ""
Write-Host "=== Краткая выжимка ==="
$txt = Get-Content $out -Raw

# Показываем самые информативные секции
foreach ($pat in @(
    'FULL nginx config: sites-available/old.zorge9.com',
    'config.php — Wolf CMS',
    'Wolf CMS settings таблица — все http\*-значения',
    'live curl: /admin/login \(тот URL что 404\)|live curl: /admin/login/login \(тот URL что 404\)',
    'tail nginx error',
    'tail php-fpm error'
)) {
    if ($txt -match "(?ms)=== ($pat) ===\s*\r?\n(.+?)(?:\r?\n=== |\Z)") {
        $body = $Matches[2].Trim()
        if ($body.Length -gt 1500) { $body = $body.Substring(0,1500) + "`n... (cut, см. полный лог)" }
        Write-Host ""
        Write-Host "--- $($Matches[1]) ---"
        Write-Host $body
    }
}

Write-Host ""
Write-Host "Полный лог: $out"
Write-Host "Покажи мне этот файл (или вставь сюда хотя бы секции FULL nginx config / config.php / live curl) — точечно скажу что чинить."
