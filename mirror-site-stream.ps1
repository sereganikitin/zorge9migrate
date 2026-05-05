#Requires -Version 7.0
<#
mirror-site-stream.ps1 — потоковый бэкап сайта.

STAGE 1: на сервере запускается `tar -cf - --exclude=.git`, его stdout
         стримится по SSH.NET напрямую в локальный .tar файл.
         Один TCP-коннект, один поток — без per-file SFTP overhead.

STAGE 2: локально распаковка .tar в files/ через System.Formats.Tar.

Параметры:
  -ExtractOnly  пропустить скачивание, только распаковать существующий .tar
  -NoExtract    только скачать, не распаковывать
#>
[CmdletBinding()]
param(
    [string]$EnvFile      = (Join-Path $PSScriptRoot '.env'),
    [string]$RemoteParent = '/var/www',
    [string]$RemoteName   = 'old.zorge9.com',
    [string]$DestDir      = (Join-Path $PSScriptRoot 'files'),
    [string]$TarPath      = (Join-Path $PSScriptRoot 'files\old.zorge9.com.tar'),
    [string]$LogDir       = (Join-Path $PSScriptRoot 'logs'),
    [switch]$ExtractOnly,
    [switch]$NoExtract
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
$log = Join-Path $LogDir "stream_$ts.log"
function Log($m) {
    $line = '{0}  {1}' -f (Get-Date -Format 'HH:mm:ss'), $m
    Add-Content $log $line -Encoding UTF8
    Write-Host $line
}

Import-Module Posh-SSH

# === STAGE 1: stream tar ===
if (-not $ExtractOnly) {
    Log '=== STAGE 1: streaming tar from server ==='
    Log ("Server: {0}:{1}" -f $envVars['SFTP_HOST'], $port)
    Log ("Remote: {0}/{1} (excluding .git)" -f $RemoteParent, $RemoteName)
    Log ("Local : {0}" -f $TarPath)

    $client = [Renci.SshNet.SshClient]::new($envVars['SFTP_HOST'], $port, $envVars['SFTP_USER'], $envVars['SFTP_PASSWORD'])
    $client.ConnectionInfo.Timeout = [TimeSpan]::FromSeconds(60)
    $client.KeepAliveInterval = [TimeSpan]::FromSeconds(30)
    $client.Connect()
    Log "Connected via SSH.NET"

    # tar uncompressed (контент уже сжат — gzip только тратит CPU без пользы)
    # 2>/dev/null глушит stderr (warning'и про file-changed), нам нужен только stdout
    $cmdText = "tar -cf - --warning=no-file-changed --exclude='.git' -C '$RemoteParent' '$RemoteName' 2>/dev/null"
    $cmd = $client.CreateCommand($cmdText)
    $cmd.CommandTimeout = [TimeSpan]::FromHours(8)

    $out = [System.IO.File]::Create($TarPath)
    $total = 0L
    try {
        $async = $cmd.BeginExecute()
        $stream = $cmd.OutputStream
        $buf = New-Object byte[] 4194304   # 4 MB
        $sw = [Diagnostics.Stopwatch]::StartNew()
        $lastReport = [TimeSpan]::FromSeconds(-5)

        while (($n = $stream.Read($buf, 0, $buf.Length)) -gt 0) {
            $out.Write($buf, 0, $n)
            $total += $n
            if (($sw.Elapsed - $lastReport).TotalSeconds -ge 5) {
                $rate = if ($sw.Elapsed.TotalSeconds -gt 0) { $total / $sw.Elapsed.TotalSeconds } else { 0 }
                Log ("[stream] {0:N2} GB  rate {1:N1} MB/s  elapsed {2:hh\:mm\:ss}" -f
                    ($total/1GB), ($rate/1MB), $sw.Elapsed)
                $lastReport = $sw.Elapsed
            }
        }
        $cmd.EndExecute($async)
        $sw.Stop()
        Log ("Stream finished: {0:N2} GB in {1:hh\:mm\:ss}, exitStatus={2}" -f
            ($total/1GB), $sw.Elapsed, $cmd.ExitStatus)
    } finally {
        $out.Dispose()
        $client.Disconnect()
        $client.Dispose()
    }

    if ($total -lt 10MB) {
        throw "Скачано слишком мало ($total bytes) — что-то пошло не так. Проверьте лог."
    }
}

# === STAGE 2: extract ===
if (-not $NoExtract) {
    Log ''
    Log '=== STAGE 2: extracting tar ==='
    if (-not (Test-Path $TarPath)) { throw "Tar not found: $TarPath" }
    $tarSize = (Get-Item $TarPath).Length
    Log ("Tar: {0:N2} GB -> {1}" -f ($tarSize/1GB), $DestDir)

    $fs = [System.IO.File]::OpenRead($TarPath)
    $tr = [System.Formats.Tar.TarReader]::new($fs)
    $nFiles = 0; $nDirs = 0; $nSym = 0; $bytesProc = 0L
    $sw = [Diagnostics.Stopwatch]::StartNew()
    $lastReport = [TimeSpan]::FromSeconds(-5)
    try {
        while ($null -ne ($entry = $tr.GetNextEntry($false))) {
            $rel  = $entry.Name -replace '/', '\'
            $full = Join-Path $DestDir $rel
            $parent = Split-Path $full -Parent
            if ($parent -and -not (Test-Path -LiteralPath $parent)) {
                New-Item -ItemType Directory -Force -Path $parent | Out-Null
            }

            switch ($entry.EntryType) {
                'Directory' {
                    New-Item -ItemType Directory -Force -Path $full | Out-Null
                    $nDirs++
                }
                {$_ -in 'RegularFile','V7RegularFile'} {
                    $f = [System.IO.File]::Create($full)
                    try {
                        if ($entry.DataStream) {
                            $entry.DataStream.CopyTo($f)
                            $bytesProc += $entry.Length
                        }
                    } finally { $f.Dispose() }
                    $nFiles++
                }
                'SymbolicLink' {
                    Set-Content -Path "$full.symlink.txt" -Value $entry.LinkName -Encoding UTF8
                    $nSym++
                }
                'HardLink' {
                    $tgt = Join-Path $DestDir (($entry.LinkName) -replace '/', '\')
                    if (Test-Path -LiteralPath $tgt) { Copy-Item -LiteralPath $tgt $full -Force; $nFiles++ }
                    else { Set-Content -Path "$full.hardlink.txt" -Value $entry.LinkName -Encoding UTF8; $nSym++ }
                }
            }

            if (($sw.Elapsed - $lastReport).TotalSeconds -ge 5) {
                Log ("[extract] files={0} dirs={1} sym={2}  data={3:N2} GB  elapsed {4:hh\:mm\:ss}" -f
                    $nFiles, $nDirs, $nSym, ($bytesProc/1GB), $sw.Elapsed)
                $lastReport = $sw.Elapsed
            }
        }
    } finally {
        $tr.Dispose(); $fs.Dispose()
    }
    Log ("Extracted: files={0} dirs={1} sym={2}, data={3:N2} GB, time {4:hh\:mm\:ss}" -f
        $nFiles, $nDirs, $nSym, ($bytesProc/1GB), $sw.Elapsed)
}

Log ''
Log '=== ALL DONE ==='
Log "Log: $log"
