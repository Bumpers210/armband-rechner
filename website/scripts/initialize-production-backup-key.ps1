[CmdletBinding(SupportsShouldProcess = $true)]
param(
    [string]$SshAlias = 'carmaja-production-ionos',
    [string]$KeyId = 'carmaja-backup-2026-08-v1',
    [string]$RemoteKeyFile = 'carmaja-private-shop/config/backup-key.php'
)

$ErrorActionPreference = 'Stop'
$invalidConfiguration = $KeyId -notmatch '^[A-Za-z0-9._-]{1,80}$' `
    -or $SshAlias -notmatch '^[A-Za-z0-9._-]+$' `
    -or $RemoteKeyFile -notmatch '^carmaja-private-shop/config/[A-Za-z0-9._-]+$'
if ($invalidConfiguration) {
    throw 'Die Schlüsselkonfiguration ist unsicher.'
}

$agentRoot = Join-Path $env:LOCALAPPDATA 'Carmaja\BackupAgent'
New-Item -ItemType Directory -Path $agentRoot -Force | Out-Null
$identity = '*' + [System.Security.Principal.WindowsIdentity]::GetCurrent().User.Value
& icacls.exe $agentRoot '/inheritance:r' '/grant:r' ("{0}:(OI)(CI)F" -f $identity) | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Der Agentordner konnte nicht geschützt werden.' }

$bytes = New-Object byte[] 32
$generator = [System.Security.Cryptography.RandomNumberGenerator]::Create()
$temporary = Join-Path $agentRoot ('backup-key-' + [guid]::NewGuid().ToString('N') + '.php')
$batch = Join-Path $agentRoot ('backup-key-' + [guid]::NewGuid().ToString('N') + '.sftp')
$remoteUploaded = $false
$remoteSecured = $false
try {
    $generator.GetBytes($bytes)
    $key = [Convert]::ToBase64String($bytes)
    Set-Clipboard -Value $key
    Write-Host 'Der neue Backupschlüssel liegt nur in der Zwischenablage.'
    Write-Host 'Speichere ihn jetzt im Passwortmanager und zusätzlich in der getrennten Offlinekopie.'
    $confirmation = Read-Host 'Gib danach exakt GESICHERT ein'
    if ($confirmation -cne 'GESICHERT') { throw 'Die externe Schlüsselverwahrung wurde nicht bestätigt.' }

    $payload = "<?php`n`ndeclare(strict_types=1);`n`nreturn [`n    'keyId' => '$KeyId',`n    'key' => '$key',`n];`n"
    [System.IO.File]::WriteAllText($temporary, $payload, [System.Text.UTF8Encoding]::new($false))
    $localSftp = $temporary.Replace('\', '/')
    [System.IO.File]::WriteAllText($batch, 'put "' + $localSftp + '" "' + $RemoteKeyFile + '"' + "`n", [System.Text.UTF8Encoding]::new($false))

    if ($PSCmdlet.ShouldProcess($RemoteKeyFile, 'einmalige private Schlüsseldatei übertragen')) {
        & ssh.exe '-o' 'BatchMode=yes' '-o' 'StrictHostKeyChecking=yes' $SshAlias test '!' -e ('/home/www/' + $RemoteKeyFile) | Out-Null
        if ($LASTEXITCODE -ne 0) { throw 'Eine private Backup-Schlüsseldatei existiert bereits; sie wird nicht überschrieben.' }
        & sftp.exe '-o' 'BatchMode=yes' '-o' 'StrictHostKeyChecking=yes' '-b' $batch $SshAlias | Out-Null
        if ($LASTEXITCODE -ne 0) { throw 'Die Schlüsselübertragung ist fehlgeschlagen.' }
        $remoteUploaded = $true
        & ssh.exe '-o' 'BatchMode=yes' '-o' 'StrictHostKeyChecking=yes' $SshAlias chmod 600 ('/home/www/' + $RemoteKeyFile) | Out-Null
        if ($LASTEXITCODE -ne 0) { throw 'Die private Schlüsseldatei konnte nicht geschützt werden.' }
        $remoteAbsolute = '/home/www/' + $RemoteKeyFile
        $verifyCommand = 'test -f "{0}"; test "$(stat -c %a "{0}")" = 600' -f $remoteAbsolute
        & ssh.exe '-o' 'BatchMode=yes' '-o' 'StrictHostKeyChecking=yes' $SshAlias $verifyCommand | Out-Null
        if ($LASTEXITCODE -ne 0) { throw 'Die private Schlüsseldatei wurde nicht mit Modus 0600 bestätigt.' }
        $remoteSecured = $true
    }
}
finally {
    $generator.Dispose()
    [Array]::Clear($bytes, 0, $bytes.Length)
    if (Get-Command Set-Clipboard -ErrorAction SilentlyContinue) { Set-Clipboard -Value '' }
    Remove-Item -LiteralPath $temporary -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $batch -Force -ErrorAction SilentlyContinue
    if ($remoteUploaded -and -not $remoteSecured) {
        & ssh.exe '-o' 'BatchMode=yes' '-o' 'StrictHostKeyChecking=yes' $SshAlias rm -f ('/home/www/' + $RemoteKeyFile) | Out-Null
    }
    Remove-Variable key, payload -ErrorAction SilentlyContinue
}
