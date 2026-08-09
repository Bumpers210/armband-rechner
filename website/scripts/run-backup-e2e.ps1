[CmdletBinding()]
param([string]$SshAlias = 'carmaja-test-ionos')

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Security
if ($SshAlias -ne 'carmaja-test-ionos') { throw 'Nur der feste IONOS-Testalias ist zulässig.' }

$remoteConfig = '/home/www/carmaja-private-test/ap77-backup-e2e.php'
$remoteStage = '/home/www/carmaja-private-test/ap77-backup-e2e-stage'
$remoteData = '/home/www/carmaja-private-test/ap77-backup-e2e-data'
$localServiceCandidate = Join-Path $PSScriptRoot '..\test-api-private\program\production-backup.php'
$localRunnerCandidate = Join-Path $PSScriptRoot 'production-backup-e2e.php'
$localService = (Resolve-Path -LiteralPath $localServiceCandidate).Path
$localRunner = (Resolve-Path -LiteralPath $localRunnerCandidate).Path
$batch = Join-Path $env:TEMP ('carmaja-ap77-e2e-' + [guid]::NewGuid().ToString('N') + '.sftp')
$agentRoot = Join-Path $env:LOCALAPPDATA 'Carmaja\BackupAgent'
$retryCache = Join-Path $agentRoot 'ap77-e2e-config.dpapi'
$configTemporary = Join-Path $agentRoot ('ap77-e2e-retry-' + [guid]::NewGuid().ToString('N') + '.php')
$configBatch = Join-Path $agentRoot ('ap77-e2e-retry-' + [guid]::NewGuid().ToString('N') + '.sftp')
$stageOwned = $false
$remoteCleanupRequired = $false
$e2ePassed = $false

function Restore-E2eConfigFromCache {
    if (-not (Test-Path -LiteralPath $retryCache -PathType Leaf)) {
        throw 'Die private Serverkonfiguration fehlt und es ist keine geschuetzte Wiederaufnahme-Konfiguration vorhanden.'
    }

    $protectedBytes = [IO.File]::ReadAllBytes($retryCache)
    $plainBytes = $null
    try {
        $plainBytes = [System.Security.Cryptography.ProtectedData]::Unprotect(
            $protectedBytes,
            $null,
            [System.Security.Cryptography.DataProtectionScope]::CurrentUser
        )
        [IO.File]::WriteAllBytes($configTemporary, $plainBytes)
        $upload = 'put "' + $configTemporary.Replace('\', '/') + '" "carmaja-private-test/ap77-backup-e2e.php"'
        [IO.File]::WriteAllText($configBatch, $upload + "`n", [Text.UTF8Encoding]::new($false))
        & sftp.exe '-o' 'BatchMode=yes' '-o' 'StrictHostKeyChecking=yes' '-b' $configBatch $SshAlias | Out-Null
        if ($LASTEXITCODE -ne 0) { throw 'Die geschuetzte E2E-Konfiguration konnte nicht wieder bereitgestellt werden.' }
        $verifyConfigCommand = 'chmod 600 "{0}"; test -f "{0}"; test "$(stat -c %a "{0}")" = 600' -f $remoteConfig
        & ssh.exe '-o' 'BatchMode=yes' '-o' 'StrictHostKeyChecking=yes' $SshAlias $verifyConfigCommand | Out-Null
        if ($LASTEXITCODE -ne 0) { throw 'Die wieder bereitgestellte E2E-Konfiguration hat nicht Modus 0600.' }
    }
    finally {
        if ($null -ne $plainBytes) { [Array]::Clear($plainBytes, 0, $plainBytes.Length) }
        [Array]::Clear($protectedBytes, 0, $protectedBytes.Length)
        Remove-Item -LiteralPath $configTemporary -Force -ErrorAction SilentlyContinue
        Remove-Item -LiteralPath $configBatch -Force -ErrorAction SilentlyContinue
    }
}

try {
    if (-not (Test-Path -LiteralPath $localService -PathType Leaf) -or -not (Test-Path -LiteralPath $localRunner -PathType Leaf)) {
        throw 'Die lokalen E2E-Artefakte fehlen.'
    }
    & ssh.exe '-o' 'BatchMode=yes' '-o' 'StrictHostKeyChecking=yes' $SshAlias "test -f $remoteConfig" | Out-Null
    if ($LASTEXITCODE -ne 0) { Restore-E2eConfigFromCache }
    $remoteCleanupRequired = $true
    $preflightCommand = 'set -eu; test -f "{0}"; test "$(stat -c %a "{0}")" = 600; test ! -e "{1}"; test ! -e "{2}"; mkdir -m 700 -p "{1}/program"; echo E2E_STAGE_READY' `
        -f $remoteConfig, $remoteStage, $remoteData
    $preflight = & ssh.exe '-o' 'BatchMode=yes' '-o' 'StrictHostKeyChecking=yes' $SshAlias $preflightCommand 2>&1
    if ($LASTEXITCODE -ne 0 -or ($preflight -join "`n") -notmatch 'E2E_STAGE_READY') {
        throw 'Der E2E-Vorflug ist fehlgeschlagen; Konfiguration, Modus oder Zielisolation stimmen nicht.'
    }
    $stageOwned = $true
    $serviceUpload = 'put "' + $localService.Replace('\', '/') + '" "carmaja-private-test/ap77-backup-e2e-stage/program/production-backup.php"'
    $runnerUpload = 'put "' + $localRunner.Replace('\', '/') + '" "carmaja-private-test/ap77-backup-e2e-stage/e2e.php"'
    $commands = @($serviceUpload, $runnerUpload)
    if ($commands.Count -ne 2 -or $commands[0] -match '\sput\s' -or $commands[1] -match '\sput\s') {
        throw 'Der E2E-SFTP-Batch ist nicht eindeutig getrennt.'
    }
    [IO.File]::WriteAllLines($batch, $commands, [Text.UTF8Encoding]::new($false))
    & sftp.exe '-o' 'BatchMode=yes' '-o' 'StrictHostKeyChecking=yes' '-b' $batch $SshAlias | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'Der E2E-Upload ist fehlgeschlagen.' }
    $uploadVerification = & ssh.exe '-o' 'BatchMode=yes' '-o' 'StrictHostKeyChecking=yes' $SshAlias `
        "set -eu; test -s $remoteStage/e2e.php; test -s $remoteStage/program/production-backup.php; echo E2E_UPLOAD_READY" 2>&1
    if ($LASTEXITCODE -ne 0 -or ($uploadVerification -join "`n") -notmatch 'E2E_UPLOAD_READY') {
        throw 'Der E2E-Upload konnte am privaten Ziel nicht bestaetigt werden.'
    }
    $remoteExecution = '/usr/bin/php8.4 "{0}" "{1}" "{2}"' -f `
        "$remoteStage/e2e.php", $remoteConfig, "$remoteStage/program/production-backup.php"
    $json = & ssh.exe '-o' 'BatchMode=yes' '-o' 'StrictHostKeyChecking=yes' $SshAlias $remoteExecution 2>&1
    if ($LASTEXITCODE -ne 0) { throw 'Der private Backup-E2E-Lauf ist fehlgeschlagen.' }
    $result = ($json -join "`n") | ConvertFrom-Json
    if ($result.status -ne 'passed' -or $result.tls -ne 'active' -or $result.cleanup -ne 'complete') {
        throw 'Der private Backup-E2E-Nachweis ist unvollständig.'
    }
    $e2ePassed = $true
    [pscustomobject]@{
        status = 'passed'
        tls = 'active'
        cleanup = 'complete'
    } | ConvertTo-Json -Compress
}
finally {
    Remove-Item -LiteralPath $batch -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $configTemporary -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $configBatch -Force -ErrorAction SilentlyContinue
    if ($remoteCleanupRequired -or $stageOwned) {
        & ssh.exe '-o' 'BatchMode=yes' '-o' 'StrictHostKeyChecking=yes' $SshAlias `
            "rm -rf $remoteStage $remoteData; rm -f $remoteConfig; test ! -e $remoteStage; test ! -e $remoteData; test ! -e $remoteConfig" | Out-Null
        if ($LASTEXITCODE -ne 0) { throw 'Der E2E-Cleanup wurde nicht vollständig bestätigt.' }
    }
}

if ($e2ePassed) {
    Remove-Item -LiteralPath $retryCache -Force -ErrorAction Stop
}
