[CmdletBinding(SupportsShouldProcess = $true)]
param(
    [string]$SshAlias = 'carmaja-production-ionos',
    [string]$OneDriveRoot = 'D:\Carmaja-OneDrive\OneDrive',
    [string]$BackupTargetRoot = 'D:\Carmaja-OneDrive\OneDrive\Carmaja-OneDrive',
    [string]$TaskName = 'Carmaja Production Backup Pull'
)

$ErrorActionPreference = 'Stop'
if ($SshAlias -ne 'carmaja-production-ionos' `
    -or $OneDriveRoot -ne 'D:\Carmaja-OneDrive\OneDrive' `
    -or $BackupTargetRoot -ne 'D:\Carmaja-OneDrive\OneDrive\Carmaja-OneDrive') {
    throw 'Der produktive Taskvertrag wurde verändert.'
}
$source = Join-Path $PSScriptRoot 'pull-production-backups.ps1'
if (-not (Test-Path -LiteralPath $source -PathType Leaf)) {
    throw 'Das Pullskript fehlt.'
}
$agentRoot = Join-Path $env:LOCALAPPDATA 'Carmaja\BackupAgent'
$target = Join-Path $agentRoot 'pull-production-backups.ps1'
$identity = '*' + [System.Security.Principal.WindowsIdentity]::GetCurrent().User.Value

if ($PSCmdlet.ShouldProcess($agentRoot, 'privaten Backup-Agent und Windows-Task installieren')) {
    New-Item -ItemType Directory -Path $agentRoot -Force | Out-Null
    & icacls.exe $agentRoot '/inheritance:r' '/grant:r' ("{0}:(OI)(CI)F" -f $identity) | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'Der Agentordner konnte nicht geschützt werden.' }
    Copy-Item -LiteralPath $source -Destination $target -Force

    $powerShell = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'
    if (-not (Test-Path -LiteralPath $powerShell -PathType Leaf)) {
        throw 'Windows PowerShell fuer den geplanten Task fehlt.'
    }
    $arguments = '-NoLogo -NoProfile -NonInteractive -ExecutionPolicy Bypass -File "' + $target + '" -SshAlias "' + $SshAlias + '" -OneDriveRoot "' + $OneDriveRoot + '" -BackupTargetRoot "' + $BackupTargetRoot + '"'
    $action = New-ScheduledTaskAction -Execute $powerShell -Argument $arguments
    $logon = New-ScheduledTaskTrigger -AtLogOn -User ([System.Security.Principal.WindowsIdentity]::GetCurrent().Name)
    $repeat = New-ScheduledTaskTrigger -Once -At ([datetime]::Now.AddMinutes(1)) -RepetitionInterval ([timespan]::FromMinutes(30)) -RepetitionDuration ([timespan]::FromDays(3650))
    $settings = New-ScheduledTaskSettingsSet -ExecutionTimeLimit ([timespan]::FromMinutes(10)) -MultipleInstances IgnoreNew -StartWhenAvailable
    $principal = New-ScheduledTaskPrincipal -UserId ([System.Security.Principal.WindowsIdentity]::GetCurrent().Name) -LogonType Interactive -RunLevel Limited
    Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger @($logon, $repeat) -Settings $settings -Principal $principal -Force | Out-Null
}
