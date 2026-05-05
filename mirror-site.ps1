#Requires -Version 7.0
<#
mirror-site.ps1 — зеркалирование /var/www/old.zorge9.com по SFTP,
исключая .git/. Без архивации на сервере (там нет места).
Restart-safe: уже скачанные файлы того же размера пропускаются.
#>
[CmdletBinding()]
param(
    [string]$EnvFile   = (Join-Path $PSScriptRoot '.env'),
    [string]$RemoteDir = '/var/www/old.zorge9.com',
    [string]$DestDir   = (Join-Path $PSScriptRoot 'files\old.zorge9.com'),
    [string]$LogDir    = (Join-Path $PSScriptRoot 'logs')
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

New-Item -ItemType Directory -Force -Path $DestDir, $LogDir | Out-Null
$ts  = Get-Date -Format 'yyyy-MM-dd_HHmmss'
$log = Join-Path $LogDir "site_$ts.log"
function Log($m) {
    $line = '{0}  {1}' -f (Get-Date -Format 'HH:mm:ss'), $m
    Add-Content $log $line -Encoding UTF8
    Write-Host $line
}

Import-Module Posh-SSH
$cred = New-Object System.Management.Automation.PSCredential($envVars['SFTP_USER'], (ConvertTo-SecureString $envVars['SFTP_PASSWORD'] -AsPlainText -Force))

Log '=== Site mirror: connecting SSH + SFTP ==='
$ssh  = New-SSHSession  -ComputerName $envVars['SFTP_HOST'] -Port $port -Credential $cred -AcceptKey -ErrorAction Stop
$sftp = New-SFTPSession -ComputerName $envVars['SFTP_HOST'] -Port $port -Credential $cred -AcceptKey -ErrorAction Stop
Log ("Connected. SSH={0}, SFTP={1}" -f $ssh.SessionId, $sftp.SessionId)

# --- 1. список файлов с сервера, исключая .git ---
Log "=== Listing files (excluding .git) ==="
$findCmd = @'
find /var/www/old.zorge9.com -name .git -prune -o -type f -printf '%s\t%p\n'
'@
$res = Invoke-SSHCommand -SessionId $ssh.SessionId -Command $findCmd -TimeOut 600
$files = New-Object System.Collections.Generic.List[object]
foreach ($l in $res.Output) {
    if ($l -match '^(\d+)\t(.+)$') {
        [void]$files.Add([PSCustomObject]@{ Size = [int64]$Matches[1]; Remote = $Matches[2] })
    }
}
$totalCount = $files.Count
$totalBytes = 0; foreach ($f in $files) { $totalBytes += $f.Size }
Log ("Files: {0}, total {1:N2} GB" -f $totalCount, ($totalBytes/1GB))

# --- 2. итерация ---
$prefix = $RemoteDir.TrimEnd('/') + '/'
$downloaded = 0; $skipped = 0; $errors = 0
$bytesDone = 0L; $bytesSkipped = 0L
$swAll = [System.Diagnostics.Stopwatch]::StartNew()
$lastReport = [TimeSpan]::Zero
$idx = 0
foreach ($f in $files) {
    $idx++
    $rel = $f.Remote
    if ($rel.StartsWith($prefix)) { $rel = $rel.Substring($prefix.Length) } else { continue }

    $localPath = Join-Path $DestDir ($rel -replace '/', '\')
    $localDir  = Split-Path $localPath -Parent

    # уже скачано?
    if (Test-Path -LiteralPath $localPath) {
        $existing = Get-Item -LiteralPath $localPath
        if ($existing.Length -eq $f.Size) {
            $skipped++; $bytesSkipped += $f.Size; continue
        }
    }

    if (-not (Test-Path -LiteralPath $localDir)) { New-Item -ItemType Directory -Force -Path $localDir | Out-Null }

    try {
        Get-SFTPItem -SessionId $sftp.SessionId -Path $f.Remote -Destination $localDir -Force -ErrorAction Stop
        $downloaded++; $bytesDone += $f.Size
    } catch {
        $errors++
        Log ("ERROR  {0}  ->  {1}" -f $f.Remote, $_.Exception.Message)
    }

    # отчёт каждые 30 сек
    if (($swAll.Elapsed - $lastReport).TotalSeconds -ge 30) {
        $rate = if ($swAll.Elapsed.TotalSeconds -gt 0) { $bytesDone / $swAll.Elapsed.TotalSeconds } else { 0 }
        $remainingBytes = $totalBytes - $bytesDone - $bytesSkipped
        $eta = if ($rate -gt 0) { [TimeSpan]::FromSeconds($remainingBytes / $rate) } else { [TimeSpan]::FromHours(99) }
        Log ("[progress] {0}/{1} ({2:P1})  done {3:N2} GB  skipped {4:N2} GB  rate {5:N1} MB/s  ETA {6:hh\:mm\:ss}" -f
            $idx, $totalCount, ($idx/$totalCount), ($bytesDone/1GB), ($bytesSkipped/1GB), ($rate/1MB), $eta)
        $lastReport = $swAll.Elapsed
    }
}
$swAll.Stop()

Remove-SFTPSession -SessionId $sftp.SessionId | Out-Null
Remove-SSHSession  -SessionId $ssh.SessionId  | Out-Null

Log ''
Log '=== Done ==='
Log ("Downloaded: {0} files, {1:N2} GB" -f $downloaded, ($bytesDone/1GB))
Log ("Skipped:    {0} files, {1:N2} GB" -f $skipped, ($bytesSkipped/1GB))
Log ("Errors:     {0}" -f $errors)
Log ("Total time: {0:hh\:mm\:ss}" -f $swAll.Elapsed)
Log ("Log file:   {0}" -f $log)
