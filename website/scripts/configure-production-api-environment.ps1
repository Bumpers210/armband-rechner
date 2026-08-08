[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Read-CanonicalAbsoluteUnixPath([string]$VariableName) {
    while ($true) {
        $value = (Read-Host "$VariableName (absoluter Unix-Pfad)").Trim()

        if ($value -match '^/[A-Za-z0-9._/-]+$' -and $value -notmatch '\.\.') {
            return $value
        }

        Write-Host 'Ungueltiger Pfad. Erlaubt sind absolute, kanonische Unix-Pfade ohne ..' -ForegroundColor Yellow
    }
}

if (-not (Get-Command gh -ErrorAction SilentlyContinue)) {
    throw 'GitHub CLI (gh) ist nicht installiert oder nicht im PATH.'
}

& gh auth status | Out-Null
if ($LASTEXITCODE -ne 0) {
    throw 'GitHub CLI ist nicht angemeldet.'
}

$values = [ordered]@{
    CARMAJA_PRODUCTION_API_WEBROOT = Read-CanonicalAbsoluteUnixPath 'CARMAJA_PRODUCTION_API_WEBROOT'
    CARMAJA_PRODUCTION_PRIVATE_DIR = Read-CanonicalAbsoluteUnixPath 'CARMAJA_PRODUCTION_PRIVATE_DIR'
    CARMAJA_PRODUCTION_API_DEPLOY_WORKSPACE = Read-CanonicalAbsoluteUnixPath 'CARMAJA_PRODUCTION_API_DEPLOY_WORKSPACE'
    CARMAJA_PRODUCTION_API_RUNTIME_CONFIG = Read-CanonicalAbsoluteUnixPath 'CARMAJA_PRODUCTION_API_RUNTIME_CONFIG'
}

if ($values.CARMAJA_PRODUCTION_API_RUNTIME_CONFIG -notlike "$($values.CARMAJA_PRODUCTION_PRIVATE_DIR)/*") {
    throw 'Die Runtime-Konfiguration muss innerhalb des privaten Datenpfads liegen.'
}

$uniqueRoots = @(
    $values.CARMAJA_PRODUCTION_API_WEBROOT,
    $values.CARMAJA_PRODUCTION_PRIVATE_DIR,
    $values.CARMAJA_PRODUCTION_API_DEPLOY_WORKSPACE
) | Select-Object -Unique

if ($uniqueRoots.Count -ne 3) {
    throw 'API-Webroot, privater Datenpfad und Deploy-Workspace muessen verschieden sein.'
}

Write-Host 'Die eingegebenen Pfade werden nicht ausgegeben.'
$confirmation = Read-Host 'Variablen im GitHub-Environment carmaja-production setzen und das Deploy-Gate auf false setzen? (JA)'
if ($confirmation -cne 'JA') {
    throw 'Abgebrochen; keine GitHub-Variable wurde geaendert.'
}

foreach ($entry in $values.GetEnumerator()) {
    & gh variable set $entry.Key --env carmaja-production --body $entry.Value
    if ($LASTEXITCODE -ne 0) {
        throw "GitHub-Variable $($entry.Key) konnte nicht gesetzt werden."
    }
}

& gh variable set CARMAJA_PRODUCTION_API_DEPLOY_ENABLED --env carmaja-production --body false
if ($LASTEXITCODE -ne 0) {
    throw 'API-Deploy-Gate konnte nicht auf false gesetzt werden.'
}

Write-Host 'Geschuetzte API-Variablen wurden gesetzt; das Deploy-Gate bleibt deaktiviert.'
