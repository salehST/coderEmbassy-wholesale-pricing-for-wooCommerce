param([string]$Tree = 'HEAD')
$ErrorActionPreference = 'Stop'
$root = Split-Path $PSScriptRoot -Parent
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
function Git-Bytes([string[]]$GitArgs) {
    $info = New-Object System.Diagnostics.ProcessStartInfo
    $info.FileName = 'git'
    $info.WorkingDirectory = $root
    $info.Arguments = $GitArgs -join ' '
    $info.UseShellExecute = $false
    $info.RedirectStandardOutput = $true
    $process = [System.Diagnostics.Process]::Start($info)
    $memory = New-Object System.IO.MemoryStream
    $process.StandardOutput.BaseStream.CopyTo($memory)
    $process.WaitForExit()
    if ($process.ExitCode -ne 0) { throw "git failed: $GitArgs" }
    return ,$memory.ToArray()
}
$sha = (& git -C $root rev-parse --verify "$Tree^{commit}").Trim()
if ($LASTEXITCODE -ne 0 -or $sha -notmatch '^[0-9a-f]{40}$') { throw 'Invalid commit' }
$entries = [Text.Encoding]::UTF8.GetString((Git-Bytes @('ls-tree','-r','--full-tree',$sha))).TrimEnd("`n").Split("`n")
$files = @()
foreach ($line in $entries) {
    if ($line -notmatch '^100644 blob ([0-9a-f]{40})\t(.+)$') { throw "Unsupported tree entry: $line" }
    $blob = $Matches[1]; $name = $Matches[2]
    # Explicit shipping allowlist; repository documentation and tooling never ship.
    if ($name -match '^(assets/|includes/)' -or $name -in @('coderembassy-wholesale-pricing.php','readme.txt')) {
        $files += [pscustomobject]@{ Name=$name; Blob=$blob }
    }
}
if ($files.Count -eq 0) { throw 'Empty plugin tree' }
$header = [Text.Encoding]::UTF8.GetString((Git-Bytes @('cat-file','blob',($files | Where-Object Name -eq 'coderembassy-wholesale-pricing.php').Blob)))
if ($header -notmatch '(?m)^ \* Version:\s+([0-9]+\.[0-9]+\.[0-9]+)\s*$') { throw 'Missing version' }
$version = $Matches[1]
$dist = Join-Path $root 'dist'
[IO.Directory]::CreateDirectory($dist) | Out-Null
$zipPath = Join-Path $dist "coderembassy-wholesale-pricing-$version.zip"
$stream = [IO.File]::Open($zipPath, [IO.FileMode]::Create)
$zip = New-Object IO.Compression.ZipArchive($stream, [IO.Compression.ZipArchiveMode]::Create)
try {
    foreach ($file in $files) {
        $entry = $zip.CreateEntry(('coderembassy-wholesale-pricing/' + $file.Name))
        $entry.LastWriteTime = [DateTimeOffset]::new(2000,1,1,0,0,0,[TimeSpan]::Zero)
        $bytes = Git-Bytes @('cat-file','blob',$file.Blob)
        $output = $entry.Open()
        try { $output.Write($bytes,0,$bytes.Length) } finally { $output.Dispose() }
    }
} finally { $zip.Dispose(); $stream.Dispose() }
$zip = [IO.Compression.ZipFile]::OpenRead($zipPath)
try {
    if ($zip.Entries.Count -ne $files.Count) { throw 'Archive count mismatch' }
    foreach ($file in $files) {
        $entry = $zip.GetEntry(('coderembassy-wholesale-pricing/' + $file.Name))
        if ($null -eq $entry) { throw "Missing archive entry: $($file.Name)" }
        $input = $entry.Open(); $memory = New-Object IO.MemoryStream
        try { $input.CopyTo($memory) } finally { $input.Dispose() }
        $expected = Git-Bytes @('cat-file','blob',$file.Blob)
        if ([Convert]::ToBase64String($memory.ToArray()) -cne [Convert]::ToBase64String($expected)) { throw "Byte mismatch: $($file.Name)" }
        Write-Output $entry.FullName
    }
} finally { $zip.Dispose() }
Write-Output "Verified $($files.Count) files against git commit $sha"
Write-Output "SHA-256 $((Get-FileHash -LiteralPath $zipPath -Algorithm SHA256).Hash.ToLowerInvariant())"
Write-Output $zipPath
