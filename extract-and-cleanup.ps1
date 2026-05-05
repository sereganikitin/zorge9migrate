#Requires -Version 7.0
<#
extract-and-cleanup.ps1
 1) Распаковывает db/zorge9_dump/configs.tar.gz в configs/ через .NET (без tar.exe).
    Симлинки сохраняет как <name>.symlink.txt с целью внутри (Windows без admin
    не может создавать настоящие симлинки; целевые файлы из архива всё равно
    извлекаются как обычные файлы там, где они есть в архиве).
 2) Удаляет /tmp/zorge9_dump на сервере.
#>
[CmdletBinding()]
param(
    [string]$EnvFile  = (Join-Path $PSScriptRoot '.env'),
    [string]$Archive  = (Join-Path $PSScriptRoot 'db\zorge9_dump\configs.tar.gz'),
    [string]$Dest     = (Join-Path $PSScriptRoot 'configs'),
    [string]$LogDir   = (Join-Path $PSScriptRoot 'logs')
)
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$ts  = Get-Date -Format 'yyyy-MM-dd_HHmmss'
$log = Join-Path $LogDir "extract_$ts.log"
function Log($m) { $l = '{0}  {1}' -f (Get-Date -Format 'HH:mm:ss'), $m; Add-Content $log $l -Encoding UTF8; Write-Host $l }

# --- 1: распаковка ---
if (-not (Test-Path $Archive)) { throw "Архив не найден: $Archive" }
New-Item -ItemType Directory -Force -Path $Dest | Out-Null

# гарантируем загрузку ассембли
$null = [System.Formats.Tar.TarReader]
$null = [System.IO.Compression.GZipStream]

Log "=== Распаковка $Archive -> $Dest ==="
$fs = [System.IO.File]::OpenRead($Archive)
$gz = New-Object System.IO.Compression.GZipStream($fs, [System.IO.Compression.CompressionMode]::Decompress)
$tr = New-Object System.Formats.Tar.TarReader($gz)

$nFiles = 0; $nDirs = 0; $nSym = 0; $nSkip = 0
try {
    while ($null -ne ($entry = $tr.GetNextEntry($false))) {
        $rel  = $entry.Name -replace '/', '\'
        $full = Join-Path $Dest $rel
        $parent = Split-Path $full -Parent
        if ($parent -and -not (Test-Path $parent)) { New-Item -ItemType Directory -Force -Path $parent | Out-Null }

        switch ($entry.EntryType) {
            'Directory' {
                New-Item -ItemType Directory -Force -Path $full | Out-Null
                $nDirs++
            }
            'RegularFile' {
                $out = [System.IO.File]::Create($full)
                try { if ($entry.DataStream) { $entry.DataStream.CopyTo($out) } } finally { $out.Dispose() }
                $nFiles++
            }
            'V7RegularFile' {
                $out = [System.IO.File]::Create($full)
                try { if ($entry.DataStream) { $entry.DataStream.CopyTo($out) } } finally { $out.Dispose() }
                $nFiles++
            }
            'SymbolicLink' {
                Set-Content -Path "$full.symlink.txt" -Value $entry.LinkName -Encoding UTF8
                $nSym++
            }
            'HardLink' {
                $tgt = Join-Path $Dest (($entry.LinkName) -replace '/', '\')
                if (Test-Path $tgt) { Copy-Item $tgt $full -Force; $nFiles++ }
                else { Set-Content -Path "$full.hardlink.txt" -Value $entry.LinkName -Encoding UTF8; $nSym++ }
            }
            default {
                $nSkip++
                Log ("  [skip {0}] {1}" -f $entry.EntryType, $entry.Name)
            }
        }
    }
} finally {
    $tr.Dispose(); $gz.Dispose(); $fs.Dispose()
}
Log ("Распаковано: files=$nFiles, dirs=$nDirs, symlinks=$nSym, skipped=$nSkip")
Log "Топ-уровень $Dest :"
Get-ChildItem $Dest -Force | ForEach-Object { Log ("  {0}" -f $_.Name) }

# --- 2: уборка на сервере ---
Log ''
Log '=== Уборка /tmp/zorge9_dump на сервере ==='
$envVars = @{}
Get-Content $EnvFile -Encoding UTF8 | ForEach-Object {
    if ($_ -match '^\s*(#|$)') { return }
    if ($_ -match '^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$') { $envVars[$Matches[1]] = $Matches[2].Trim().Trim('"').Trim("'") }
}
$port = if ($envVars['SFTP_PORT']) { [int]$envVars['SFTP_PORT'] } else { 22 }
Import-Module Posh-SSH
$cred = New-Object System.Management.Automation.PSCredential($envVars['SFTP_USER'], (ConvertTo-SecureString $envVars['SFTP_PASSWORD'] -AsPlainText -Force))
$ssh = New-SSHSession -ComputerName $envVars['SFTP_HOST'] -Port $port -Credential $cred -AcceptKey -ErrorAction Stop
$res = Invoke-SSHCommand -SessionId $ssh.SessionId -Command 'rm -rf /tmp/zorge9_dump && echo CLEANED && ls -la /tmp/zorge9_dump 2>&1' -TimeOut 30
$res.Output | ForEach-Object { Log "  $_" }
Remove-SSHSession -SessionId $ssh.SessionId | Out-Null
Log "Лог: $log"
