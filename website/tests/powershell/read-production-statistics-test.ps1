$ErrorActionPreference='Stop'
$statisticsScript=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../../scripts/read-production-statistics.ps1'))
$statisticsTestRoot=Join-Path ([IO.Path]::GetTempPath()) ('carmaja-statistics-read-test-'+[Guid]::NewGuid().ToString('N'))
$global:carmajaStatisticsTestChecks=0
$global:carmajaStatisticsTestTransportCalls=0
$global:carmajaStatisticsTestMockExit=0
function Check-Statistics($Condition,[string]$Message){if(-not $Condition){throw $Message};$global:carmajaStatisticsTestChecks++}
function Reject-Statistics([scriptblock]$Action){try{& $Action|Out-Null}catch{$global:carmajaStatisticsTestChecks++;return};throw 'Ungültiger Aufruf wurde akzeptiert.'}
function global:ssh.exe {
    begin{$global:carmajaStatisticsTestTransportCalls++;$global:carmajaStatisticsTestTransportArguments=@($args);$global:carmajaStatisticsTestSourceCode=''}
    process{$global:carmajaStatisticsTestSourceCode+=[string]$_}
    end{$global:LASTEXITCODE=$global:carmajaStatisticsTestMockExit;$global:carmajaStatisticsTestMockPacket}
}
$source=@{orders=0;grossGoodsMinor=0}
$businessChecks=@{}
foreach($period in @('current','previous','allAvailable')){$businessChecks[$period]=@{matches=$true;source=$source;projection=$source}}
$websiteChecks=@{}
foreach($period in @('current','previous')){$websiteChecks[$period]=@{matches=$true;sourceViews=0;copiedViews=0;sourceEntryViews=0;copiedEntryViews=0}}
$packet=@{status='ok';commerce=@{version=1;kind='commerce';environment='live';generatedAt='2026-11-19T00:00:00Z';days=@{};catalog=@()};website=@{version=3;days=@{};months=@{}};metadata=@{version=1;kind='origin';environment='live';generatedAt='2026-11-19T00:00:00Z';verification=@{tlsActive=$true;serverFilesWritten=$false;commerce=$businessChecks;website=$websiteChecks}}}
$global:carmajaStatisticsTestMockPacket=$packet|ConvertTo-Json -Depth 20 -Compress
try{
    Reject-Statistics {& $statisticsScript -OutputDirectory $statisticsTestRoot -HostAlias '-oBadOption'}
    Reject-Statistics {& $statisticsScript -OutputDirectory ([IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../../..')))}
    Reject-Statistics {& $statisticsScript -OutputDirectory $statisticsTestRoot -ExcludedConfirmationDays 'not-a-date'}
    Reject-Statistics {& $statisticsScript -OutputDirectory $statisticsTestRoot -ExcludedConfirmationDays '2026-02-30'}
    Check-Statistics ($global:carmajaStatisticsTestTransportCalls -eq 0) 'Unsichere Argumente dürfen keinen SSH-Aufruf erreichen.'
    & $statisticsScript -OutputDirectory $statisticsTestRoot|Out-Null
    Check-Statistics ($global:carmajaStatisticsTestTransportCalls -eq 1) 'Ein lesender Aufruf erwartet.'
    Check-Statistics (($global:carmajaStatisticsTestTransportArguments -join ' ') -match 'StrictHostKeyChecking=yes') 'Hostschlüsselprüfung erhalten.'
    Check-Statistics (($global:carmajaStatisticsTestTransportArguments -join ' ') -match 'PasswordAuthentication=no') 'Kein Passwort-Fallback.'
    Check-Statistics (($global:carmajaStatisticsTestTransportArguments -join ' ') -match '/usr/bin/php8.4') 'PHP 8.4 auf IONOS.'
    Check-Statistics ((Get-ChildItem -LiteralPath $statisticsTestRoot -File).Count -eq 3) 'Genau drei bereinigte lokale Auszüge.'
    $original=Get-Content -LiteralPath (Join-Path $statisticsTestRoot 'statistics-live-commerce.json') -Raw
    $global:carmajaStatisticsTestMockExit=1
    $global:carmajaStatisticsTestMockPacket='{"status":"error","stage":"commerce_projection","reason":"source_validation_failed"}'
    Reject-Statistics {& $statisticsScript -OutputDirectory $statisticsTestRoot}
    Check-Statistics ((Get-Content -LiteralPath (Join-Path $statisticsTestRoot 'statistics-live-commerce.json') -Raw) -eq $original) 'Fehlversuch erhält bisherigen Stand.'
    Check-Statistics ((Get-Content -LiteralPath (Join-Path $statisticsTestRoot 'statistics-live-commerce.json.status.json') -Raw|ConvertFrom-Json).status -eq 'error') 'Fehlgeschlagener Abruf wird am alten Stand kenntlich gemacht.'
    $global:carmajaStatisticsTestMockExit=0
    $bad=$packet|ConvertTo-Json -Depth 20|ConvertFrom-Json -Depth 20
    $bad.commerce|Add-Member -NotePropertyName customer_email -NotePropertyValue 'synthetic@example.invalid'
    $global:carmajaStatisticsTestMockPacket=$bad|ConvertTo-Json -Depth 20 -Compress
    Reject-Statistics {& $statisticsScript -OutputDirectory $statisticsTestRoot}
    $bad=$packet|ConvertTo-Json -Depth 20|ConvertFrom-Json -Depth 20
    $bad.commerce.environment='test';$global:carmajaStatisticsTestMockPacket=$bad|ConvertTo-Json -Depth 20 -Compress
    Reject-Statistics {& $statisticsScript -OutputDirectory $statisticsTestRoot}
    $bad=$packet|ConvertTo-Json -Depth 20|ConvertFrom-Json -Depth 20
    $bad.metadata.verification.tlsActive=$false;$global:carmajaStatisticsTestMockPacket=$bad|ConvertTo-Json -Depth 20 -Compress
    Reject-Statistics {& $statisticsScript -OutputDirectory $statisticsTestRoot}
    $bad=$packet|ConvertTo-Json -Depth 20|ConvertFrom-Json -Depth 20
    $bad.metadata.verification.commerce.current.projection.orders=1;$global:carmajaStatisticsTestMockPacket=$bad|ConvertTo-Json -Depth 20 -Compress
    Reject-Statistics {& $statisticsScript -OutputDirectory $statisticsTestRoot}
    $bad=$packet|ConvertTo-Json -Depth 20|ConvertFrom-Json -Depth 20
    $bad.metadata.verification.website.current.copiedViews=1;$global:carmajaStatisticsTestMockPacket=$bad|ConvertTo-Json -Depth 20 -Compress
    Reject-Statistics {& $statisticsScript -OutputDirectory $statisticsTestRoot}
    $bad=$packet|ConvertTo-Json -Depth 20|ConvertFrom-Json -Depth 20
    $bad.metadata.verification|Add-Member -NotePropertyName stripe -NotePropertyValue @{requested=$true;checkedPayments=1;records=@(@{matches=$false;currency='eur';paidMinor=1})}
    $global:carmajaStatisticsTestMockPacket=$bad|ConvertTo-Json -Depth 20 -Compress
    Reject-Statistics {& $statisticsScript -OutputDirectory $statisticsTestRoot}
    Check-Statistics ((Get-Content -LiteralPath (Join-Path $statisticsTestRoot 'statistics-live-commerce.json') -Raw) -eq $original) 'Abgelehnte Pakete überschreiben keine Daten.'
    $global:carmajaStatisticsTestMockPacket=$packet|ConvertTo-Json -Depth 20 -Compress
    & $statisticsScript -OutputDirectory $statisticsTestRoot|Out-Null
    Check-Statistics ((Get-ChildItem -LiteralPath $statisticsTestRoot -File).Count -eq 3) 'Erfolgreicher Abruf entfernt veraltete Fehlermarker.'
    $policyPacket=$packet|ConvertTo-Json -Depth 20|ConvertFrom-Json -Depth 20
    $policyPacket.metadata|Add-Member -NotePropertyName commerce -NotePropertyValue @{operatorTestFilterConfirmed=$true;excludedConfirmationDays=@('2026-08-22','2026-11-13')}
    $policyPacket.commerce|Add-Member -NotePropertyName operatorTestFilterConfirmed -NotePropertyValue $true
    $global:carmajaStatisticsTestMockPacket=$policyPacket|ConvertTo-Json -Depth 20 -Compress
    & $statisticsScript -OutputDirectory $statisticsTestRoot -ExcludedConfirmationDays '2026-08-22','2026-11-13' -OperatorTestFilterConfirmed|Out-Null
    & $statisticsScript -OutputDirectory $statisticsTestRoot|Out-Null
    $sentOptions=[regex]::Match($global:carmajaStatisticsTestSourceCode,"base64_decode\('([A-Za-z0-9+/=]+)'\)")
    Check-Statistics $sentOptions.Success 'Lesekonfiguration wird ohne Geheimwerte übertragen.'
    $decodedOptions=[Text.Encoding]::UTF8.GetString([Convert]::FromBase64String($sentOptions.Groups[1].Value))|ConvertFrom-Json
    Check-Statistics ($decodedOptions.operatorTestFilterConfirmed -eq $true -and @($decodedOptions.excludedConfirmationDays).Count -eq 2) 'Bestätigte Ausschlüsse bleiben bei gewöhnlicher Aktualisierung erhalten.'
    $clearedPacket=$policyPacket|ConvertTo-Json -Depth 20|ConvertFrom-Json -Depth 20
    $clearedPacket.commerce.operatorTestFilterConfirmed=$false
    $clearedPacket.metadata.commerce.operatorTestFilterConfirmed=$false
    $clearedPacket.metadata.commerce.excludedConfirmationDays=@()
    $global:carmajaStatisticsTestMockPacket=$clearedPacket|ConvertTo-Json -Depth 20 -Compress
    & $statisticsScript -OutputDirectory $statisticsTestRoot -ExcludedConfirmationDays @() -OperatorTestFilterConfirmed:$false|Out-Null
    $sentOptions=[regex]::Match($global:carmajaStatisticsTestSourceCode,"base64_decode\('([A-Za-z0-9+/=]+)'\)")
    $decodedOptions=[Text.Encoding]::UTF8.GetString([Convert]::FromBase64String($sentOptions.Groups[1].Value))|ConvertFrom-Json
    Check-Statistics ($decodedOptions.operatorTestFilterConfirmed -eq $false -and @($decodedOptions.excludedConfirmationDays).Count -eq 0) 'Ausdrückliche neue Vorgaben haben Vorrang vor gespeicherten Ausschlüssen.'
    $global:carmajaStatisticsTestMockPacket=$policyPacket|ConvertTo-Json -Depth 20 -Compress
    Reject-Statistics {& $statisticsScript -OutputDirectory $statisticsTestRoot -ExcludedConfirmationDays @() -OperatorTestFilterConfirmed:$false}
    Write-Output "Statistik-Lesebrücke: $global:carmajaStatisticsTestChecks Prüfungen erfolgreich; kein Serverzugriff."
}finally{
    Remove-Item Function:/ssh.exe -ErrorAction SilentlyContinue
    if(Test-Path -LiteralPath $statisticsTestRoot){
        $resolvedStatisticsTest=[IO.Path]::GetFullPath($statisticsTestRoot)
        $statisticsTempRoot=[IO.Path]::GetFullPath([IO.Path]::GetTempPath())
        if($resolvedStatisticsTest.StartsWith($statisticsTempRoot,[StringComparison]::OrdinalIgnoreCase) -and [IO.Path]::GetFileName($resolvedStatisticsTest).StartsWith('carmaja-statistics-read-test-')){Remove-Item -LiteralPath $resolvedStatisticsTest -Recurse -Force}
    }
}
