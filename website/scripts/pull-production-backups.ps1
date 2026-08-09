[CmdletBinding()]
param(
    [string]$SshAlias = 'carmaja-production-ionos',
    [string]$OneDriveRoot = 'D:\Carmaja-OneDrive',
    [string]$AgentRoot = (Join-Path $env:LOCALAPPDATA 'Carmaja\BackupAgent'),
    [string]$RemoteBackupRoot = 'carmaja-private-shop/backups',
    [string]$RemoteCli = '/home/www/carmaja-private-shop/backup.php',
    [string]$RemoteRuntime = '/home/www/carmaja-private-shop/config/runtime-config.php',
    [switch]$SelfTest
)

$ErrorActionPreference = 'Stop'
$script:ExpectedOneDriveRoot = 'D:\Carmaja-OneDrive'
$script:BackupNamePattern = '^[0-9]{8}T[0-9]{6}Z-[a-f0-9]{12}$'
$script:ExpectedFiles = @('manifest.json', 'commerce.sql.gz.cmjbkp', 'product-data.tar.gz.cmjbkp')

function Assert-SafeToken([string]$Value, [string]$Name, [string]$Pattern) {
    if ([string]::IsNullOrWhiteSpace($Value) -or $Value -notmatch $Pattern) {
        throw "Ungültiger Wert: $Name"
    }
}

function Get-FullPath([string]$Path) {
    return [System.IO.Path]::GetFullPath($Path).TrimEnd('\')
}

function Assert-StaticContract {
    $expectedAgentRoot = Get-FullPath (Join-Path $env:LOCALAPPDATA 'Carmaja\BackupAgent')
    $invalidContract = $SshAlias -ne 'carmaja-production-ionos' `
        -or (Get-FullPath $AgentRoot) -ne $expectedAgentRoot `
        -or $RemoteBackupRoot -ne 'carmaja-private-shop/backups' `
        -or $RemoteCli -ne '/home/www/carmaja-private-shop/backup.php' `
        -or $RemoteRuntime -ne '/home/www/carmaja-private-shop/config/runtime-config.php'
    if ($invalidContract) {
        throw 'Der produktive Pullvertrag wurde verändert.'
    }
}

function Assert-OneDriveReady {
    $actual = Get-FullPath $OneDriveRoot
    if (-not $actual.Equals((Get-FullPath $script:ExpectedOneDriveRoot), [StringComparison]::OrdinalIgnoreCase)) {
        throw 'Der konfigurierte OneDrive-Stamm ist nicht der freigegebene Pfad.'
    }
    if (-not (Test-Path -LiteralPath $actual -PathType Container)) {
        throw 'Der freigegebene OneDrive-Stamm fehlt.'
    }
    $accounts = @(Get-ChildItem -LiteralPath 'HKCU:\Software\Microsoft\OneDrive\Accounts' -ErrorAction SilentlyContinue | ForEach-Object {
        Get-ItemProperty -LiteralPath $_.PSPath -ErrorAction SilentlyContinue
    })
    $matchingAccounts = @($accounts | Where-Object {
        -not [string]::IsNullOrWhiteSpace([string]$_.UserFolder) `
            -and (Get-FullPath ([string]$_.UserFolder)).Equals($actual, [StringComparison]::OrdinalIgnoreCase)
    })
    if ($matchingAccounts.Count -ne 1) {
        throw 'OneDrive ist nicht auf den freigegebenen Stamm konfiguriert.'
    }
    if ($null -eq (Get-Process -Name OneDrive -ErrorAction SilentlyContinue)) {
        throw 'OneDrive läuft nicht.'
    }
}

function Protect-AgentDirectory([string]$Path) {
    New-Item -ItemType Directory -Path $Path -Force | Out-Null
    $identity = '*' + [System.Security.Principal.WindowsIdentity]::GetCurrent().User.Value
    & icacls.exe $Path '/inheritance:r' '/grant:r' ("{0}:(OI)(CI)F" -f $identity) | Out-Null
    if ($LASTEXITCODE -ne 0) {
        throw 'Der lokale Agentordner konnte nicht geschützt werden.'
    }
}

function Get-IncomingRoot {
    $driveRoot = [System.IO.Path]::GetPathRoot((Get-FullPath $OneDriveRoot))
    if ([string]::IsNullOrWhiteSpace($driveRoot)) {
        throw 'Das lokale Staginglaufwerk konnte nicht bestimmt werden.'
    }
    $path = Join-Path $driveRoot 'Carmaja-Backup-Incoming'
    $marker = Join-Path $path '.carmaja-backup-agent-owner'
    $markerValue = 'carmaja-production-backup-agent-v1'
    if (Test-Path -LiteralPath $path) {
        $item = Get-Item -LiteralPath $path -Force
        $isReparsePoint = ($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0
        if (-not $item.PSIsContainer -or $isReparsePoint -or -not (Test-Path -LiteralPath $marker -PathType Leaf)) {
            throw 'Der lokale Stagingpfad ist nicht eindeutig dem Backup-Agenten zugeordnet.'
        }
        $actualMarker = [IO.File]::ReadAllText($marker, [Text.Encoding]::UTF8).Trim()
        if ($actualMarker -cne $markerValue) {
            throw 'Der lokale Stagingpfad besitzt einen fremden Eigentumsmarker.'
        }
    }
    else {
        New-Item -ItemType Directory -Path $path | Out-Null
        [IO.File]::WriteAllText($marker, $markerValue + "`n", [Text.UTF8Encoding]::new($false))
        Protect-AgentDirectory $path
    }
    return $path
}

function Write-AgentStatus([string]$Status, [int]$DownloadedCount) {
    $path = Join-Path $AgentRoot 'status.json'
    $temporary = $path + '.tmp-' + [guid]::NewGuid().ToString('N')
    $payload = [ordered]@{
        version = 1
        status = $Status
        checkedAt = [datetime]::UtcNow.ToString('o')
        downloadedCount = $DownloadedCount
    } | ConvertTo-Json
    try {
        [IO.File]::WriteAllText($temporary, $payload + "`n", [Text.UTF8Encoding]::new($false))
        Move-Item -LiteralPath $temporary -Destination $path -Force
    }
    finally {
        Remove-Item -LiteralPath $temporary -Force -ErrorAction SilentlyContinue
    }
}

function Invoke-Checked([string]$Program, [string[]]$Arguments) {
    $output = & $Program @Arguments 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw ("Externer Prozess fehlgeschlagen: {0}" -f [System.IO.Path]::GetFileName($Program))
    }
    return @($output)
}

function Get-ReadyBackups {
    Assert-SafeToken $SshAlias 'SSH-Alias' '^[A-Za-z0-9._-]+$'
    $json = (Invoke-Checked 'ssh.exe' @(
        '-o', 'BatchMode=yes',
        '-o', 'StrictHostKeyChecking=yes',
        $SshAlias,
        '/usr/bin/php8.4', $RemoteCli, 'list-ready', $RemoteRuntime
    )) -join "`n"
    $parsed = $json | ConvertFrom-Json
    if ($parsed.status -ne 'ok' -or $null -eq $parsed.backups) {
        throw 'Die private Backup-CLI lieferte keine gültige Liste.'
    }
    return @($parsed.backups)
}

function Get-DescriptorMap($Backup) {
    $map = @{}
    foreach ($file in @($Backup.files)) {
        $name = [string]$file.name
        $invalidDescriptor = $script:ExpectedFiles -notcontains $name `
            -or $map.ContainsKey($name) `
            -or [long]$file.bytes -lt 1 `
            -or [string]$file.sha256 -notmatch '^[a-f0-9]{64}$'
        if ($invalidDescriptor) {
            throw 'Die Server-Dateiliste ist ungültig.'
        }
        $map[$name] = $file
    }
    if ($map.Count -ne $script:ExpectedFiles.Count) {
        throw 'Die Server-Dateiliste ist unvollständig.'
    }
    return $map
}

function Assert-FreeSpace([long]$IncomingBytes, [long]$LargestKnownBackup) {
    $drive = [System.IO.DriveInfo]::new(([System.IO.Path]::GetPathRoot($OneDriveRoot)))
    $required = [Math]::Max(5GB, 10L * [Math]::Max($IncomingBytes, $LargestKnownBackup))
    if ($drive.AvailableFreeSpace -lt $required) {
        throw 'Der freie lokale Speicher unterschreitet das Backup-Sicherheitslimit.'
    }
}

function Test-BackupDirectory([string]$Directory, $DescriptorMap, [string]$ExpectedBackupId) {
    if (-not (Test-Path -LiteralPath $Directory -PathType Container)) {
        return $false
    }
    foreach ($name in $script:ExpectedFiles) {
        $path = Join-Path $Directory $name
        if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
            return $false
        }
        $file = Get-Item -LiteralPath $path
        $hash = (Get-FileHash -Algorithm SHA256 -LiteralPath $path).Hash.ToLowerInvariant()
        if ($file.Length -ne [long]$DescriptorMap[$name].bytes -or $hash -ne [string]$DescriptorMap[$name].sha256) {
            return $false
        }
    }
    $manifest = Get-Content -Raw -LiteralPath (Join-Path $Directory 'manifest.json') | ConvertFrom-Json
    return [string]$manifest.backupId -eq $ExpectedBackupId
}

function Receive-Backup($Backup, [string]$TargetRoot, [string]$IncomingRoot) {
    $backupId = [string]$Backup.backupId
    Assert-SafeToken $backupId 'Backup-ID' $script:BackupNamePattern
    $descriptors = Get-DescriptorMap $Backup
    $target = Join-Path $TargetRoot $backupId
    if (Test-BackupDirectory $target $descriptors $backupId) {
        return $target
    }
    if (Test-Path -LiteralPath $target) {
        throw 'Ein vorhandenes lokales Backup stimmt nicht mit dem Server überein.'
    }

    $stage = Join-Path $IncomingRoot ($backupId + '-' + [guid]::NewGuid().ToString('N'))
    New-Item -ItemType Directory -Path $stage | Out-Null
    $batchFile = Join-Path $AgentRoot ('sftp-' + [guid]::NewGuid().ToString('N') + '.txt')
    try {
        $commands = foreach ($name in $script:ExpectedFiles) {
            $remote = $RemoteBackupRoot.TrimEnd('/') + '/ready/' + $backupId + '/' + $name
            $local = (Join-Path $stage $name).Replace('\', '/')
            if ($remote.Contains('"') -or $local.Contains('"')) {
                throw 'Ein SFTP-Pfad enthält unzulässige Zeichen.'
            }
            'get "' + $remote + '" "' + $local + '"'
        }
        [System.IO.File]::WriteAllLines($batchFile, $commands, [System.Text.UTF8Encoding]::new($false))
        Invoke-Checked 'sftp.exe' @(
            '-o', 'BatchMode=yes',
            '-o', 'StrictHostKeyChecking=yes',
            '-b', $batchFile,
            $SshAlias
        ) | Out-Null
        if (-not (Test-BackupDirectory $stage $descriptors $backupId)) {
            throw 'Das geladene Backup hat die Größen- oder Hashprüfung nicht bestanden.'
        }
        Move-Item -LiteralPath $stage -Destination $target
        return $target
    }
    finally {
        Remove-Item -LiteralPath $batchFile -Force -ErrorAction SilentlyContinue
        Remove-Item -LiteralPath $stage -Recurse -Force -ErrorAction SilentlyContinue
    }
}

function Confirm-Download($Backup) {
    $backupId = [string]$Backup.backupId
    $manifestHash = [string]$Backup.manifestSha256
    Assert-SafeToken $backupId 'Backup-ID' $script:BackupNamePattern
    Assert-SafeToken $manifestHash 'Manifest-Hash' '^[a-f0-9]{64}$'
    $json = (Invoke-Checked 'ssh.exe' @(
        '-o', 'BatchMode=yes',
        '-o', 'StrictHostKeyChecking=yes',
        $SshAlias,
        '/usr/bin/php8.4', $RemoteCli, 'acknowledge', $RemoteRuntime, $backupId, $manifestHash
    )) -join "`n"
    $result = $json | ConvertFrom-Json
    if ($result.status -ne 'acknowledged' -or $result.backupId -ne $backupId) {
        throw 'Die Downloadquittierung wurde nicht bestätigt.'
    }
}

function Select-RetentionSet([System.IO.DirectoryInfo[]]$Directories, [datetime]$NowUtc) {
    $valid = @($Directories | Where-Object { $_.Name -match $script:BackupNamePattern } | Sort-Object Name -Descending)
    $keep = @{}
    foreach ($directory in @($valid | Select-Object -First 48)) { $keep[$directory.FullName] = $true }

    $daily = @{}
    $monthly = @{}
    foreach ($directory in $valid) {
        $stamp = [datetime]::ParseExact($directory.Name.Substring(0, 15), 'yyyyMMddTHHmmss', [Globalization.CultureInfo]::InvariantCulture, [Globalization.DateTimeStyles]::AssumeUniversal).ToUniversalTime()
        if ($stamp -ge $NowUtc.AddDays(-30)) {
            $day = $stamp.ToString('yyyy-MM-dd')
            if (-not $daily.ContainsKey($day)) { $daily[$day] = $directory.FullName }
        }
        if ($stamp -ge $NowUtc.AddMonths(-12)) {
            $month = $stamp.ToString('yyyy-MM')
            if (-not $monthly.ContainsKey($month)) { $monthly[$month] = $directory.FullName }
        }
    }
    foreach ($path in $daily.Values) { $keep[$path] = $true }
    foreach ($path in $monthly.Values) { $keep[$path] = $true }
    return $keep
}

function Invoke-Retention([string]$TargetRoot) {
    $directories = @(Get-ChildItem -LiteralPath $TargetRoot -Directory | Where-Object { $_.Name -match $script:BackupNamePattern })
    if ($directories.Count -eq 0) { return }
    $keep = Select-RetentionSet $directories ([datetime]::UtcNow)
    $latest = @($directories | Sort-Object Name -Descending | Select-Object -First 1)[0].FullName
    foreach ($directory in $directories) {
        if ($directory.FullName -ne $latest -and -not $keep.ContainsKey($directory.FullName)) {
            Remove-Item -LiteralPath $directory.FullName -Recurse -Force
        }
    }
}

function Invoke-SelfTest {
    if ('20260809T120000Z-abcdef123456' -notmatch $script:BackupNamePattern) { throw 'Backup-ID-Test fehlgeschlagen.' }
    if ('../../escape' -match $script:BackupNamePattern) { throw 'Pfadtest fehlgeschlagen.' }
    $base = [datetime]::UtcNow.Date
    $items = for ($index = 0; $index -lt 80; $index++) {
        $name = $base.AddHours(-$index).ToString('yyyyMMddTHHmmssZ') + '-' + ('a' * 12)
        [System.IO.DirectoryInfo]::new((Join-Path $env:TEMP $name))
    }
    $keep = Select-RetentionSet $items ([datetime]::UtcNow)
    if ($keep.Count -lt 48) { throw 'Aufbewahrungstest fehlgeschlagen.' }
    $fixtureRoot = Join-Path $env:TEMP ('carmaja-backup-pull-selftest-' + [guid]::NewGuid().ToString('N'))
    $backupId = '20260809T120000Z-abcdef123456'
    $fixture = Join-Path $fixtureRoot $backupId
    try {
        New-Item -ItemType Directory -Path $fixture | Out-Null
        $manifest = '{"backupId":"' + $backupId + '"}'
        [IO.File]::WriteAllText((Join-Path $fixture 'manifest.json'), $manifest, [Text.UTF8Encoding]::new($false))
        [IO.File]::WriteAllBytes((Join-Path $fixture 'commerce.sql.gz.cmjbkp'), [byte[]](1, 2, 3, 4))
        [IO.File]::WriteAllBytes((Join-Path $fixture 'product-data.tar.gz.cmjbkp'), [byte[]](5, 6, 7, 8))
        $descriptors = @{}
        foreach ($name in $script:ExpectedFiles) {
            $path = Join-Path $fixture $name
            $descriptors[$name] = [pscustomobject]@{
                bytes = (Get-Item -LiteralPath $path).Length
                sha256 = (Get-FileHash -Algorithm SHA256 -LiteralPath $path).Hash.ToLowerInvariant()
            }
        }
        if (-not (Test-BackupDirectory $fixture $descriptors $backupId)) { throw 'Hashprüfungstest fehlgeschlagen.' }
        Add-Content -LiteralPath (Join-Path $fixture 'commerce.sql.gz.cmjbkp') -Value 'tampered'
        if (Test-BackupDirectory $fixture $descriptors $backupId) { throw 'Manipulationstest fehlgeschlagen.' }
        Remove-Item -LiteralPath (Join-Path $fixture 'product-data.tar.gz.cmjbkp') -Force
        if (Test-BackupDirectory $fixture $descriptors $backupId) { throw 'Teiltransfertest fehlgeschlagen.' }
    }
    finally {
        Remove-Item -LiteralPath $fixtureRoot -Recurse -Force -ErrorAction SilentlyContinue
    }
    return
}

if ($SelfTest) {
    Invoke-SelfTest
    Write-Output 'backup_pull_self_test_ok'
    exit 0
}

Assert-StaticContract
Protect-AgentDirectory $AgentRoot
$downloaded = 0
try {
    Assert-OneDriveReady
    $targetRoot = Join-Path $OneDriveRoot 'Carmaja-Perlen\Backups'
    $incomingRoot = Get-IncomingRoot
    New-Item -ItemType Directory -Path $targetRoot -Force | Out-Null

    $ready = @(Get-ReadyBackups)
    $largestExisting = 0L
    foreach ($directory in @(Get-ChildItem -LiteralPath $targetRoot -Directory -ErrorAction SilentlyContinue)) {
        if ($directory.Name -notmatch $script:BackupNamePattern) { continue }
        $size = (Get-ChildItem -LiteralPath $directory.FullName -File | Measure-Object -Property Length -Sum).Sum
        if ([long]$size -gt $largestExisting) { $largestExisting = [long]$size }
    }
    foreach ($backup in $ready) {
        if ($backup.acknowledged -eq $true) { continue }
        Assert-FreeSpace ([long]$backup.totalBytes) $largestExisting
        $target = Receive-Backup $backup $targetRoot $incomingRoot
        if (-not (Test-Path -LiteralPath $target -PathType Container)) { throw 'Die atomare Ablage ist fehlgeschlagen.' }
        Assert-OneDriveReady
        Confirm-Download $backup
        $downloaded++
        if ([long]$backup.totalBytes -gt $largestExisting) { $largestExisting = [long]$backup.totalBytes }
    }
    Invoke-Retention $targetRoot
    Write-AgentStatus 'ok' $downloaded
}
catch {
    Write-AgentStatus 'failed' $downloaded
    throw
}
