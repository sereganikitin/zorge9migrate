#Requires -Version 5.1
<#
disk-breakdown.ps1 — что съедает 70 GB в /var/www/old.zorge9.com.
Read-only. Выводит топ подпапок и крупных файлов.
#>
[CmdletBinding()]
param(
    [string]$EnvFile = (Join-Path $PSScriptRoot '.env'),
    [string]$LogDir  = (Join-Path $PSScriptRoot 'logs')
)
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$envVars = @{}
Get-Content $EnvFile -Encoding UTF8 | ForEach-Object {
    if ($_ -match '^\s*(#|$)') { return }
    if ($_ -match '^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$') { $envVars[$Matches[1]] = $Matches[2].Trim().Trim('"').Trim("'") }
}
$port = if ($envVars['SFTP_PORT']) { [int]$envVars['SFTP_PORT'] } else { 22 }

Import-Module Posh-SSH
$cred = New-Object System.Management.Automation.PSCredential($envVars['SFTP_USER'], (ConvertTo-SecureString $envVars['SFTP_PASSWORD'] -AsPlainText -Force))
$ssh  = New-SSHSession -ComputerName $envVars['SFTP_HOST'] -Port $port -Credential $cred -AcceptKey -ErrorAction Stop

$bash = @'
set +e
SITE=/var/www/old.zorge9.com
echo "=== TOP-LEVEL ($SITE) ==="
du -sh $SITE/* $SITE/.[!.]* 2>/dev/null | sort -hr
echo
echo "=== htdocs (если есть) ==="
[ -d $SITE/htdocs ] && du -sh $SITE/htdocs/* $SITE/htdocs/.[!.]* 2>/dev/null | sort -hr | head -40
echo
echo "=== Файлы > 100 MB ==="
find $SITE -type f -size +100M -printf '%s %p\n' 2>/dev/null | sort -rn | awk '{ printf "%.0f MB  %s\n", $1/1048576, $2 }' | head -30
echo
echo "=== Папки бэкапов / архивов / node_modules / vendor / cache (часто можно не качать) ==="
find $SITE -maxdepth 4 -type d \( -iname "backup*" -o -iname "*backup" -o -iname "node_modules" -o -iname "vendor" -o -iname "cache" -o -iname "logs" -o -iname "tmp" -o -iname ".git" \) 2>/dev/null | while read d; do du -sh "$d" 2>/dev/null; done | sort -hr | head -30
echo
echo "=== Расширения по объёму (топ-15) ==="
find $SITE -type f -printf "%s %f\n" 2>/dev/null | awk '{ n=split($2,a,"."); ext=(n>1?a[n]:"NONE"); s[ext]+=$1; c[ext]++ } END { for (e in s) printf "%10.0f MB  %8d files  .%s\n", s[e]/1048576, c[e], e }' | sort -rn | head -15
'@

$res = Invoke-SSHCommand -SessionId $ssh.SessionId -Command $bash -TimeOut 300
$ts  = Get-Date -Format 'yyyy-MM-dd_HHmmss'
$out = Join-Path $LogDir "disk_$ts.txt"
$res.Output | Out-File -FilePath $out -Encoding UTF8
Remove-SSHSession -SessionId $ssh.SessionId | Out-Null

$res.Output -join "`n" | Write-Host
Write-Host ""
Write-Host "Полный лог: $out"
