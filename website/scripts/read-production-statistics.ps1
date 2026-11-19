param(
    [Parameter(Mandatory=$true)][string]$OutputDirectory,
    [string]$HostAlias='carmaja-production-ionos',
    [string[]]$ExcludedConfirmationDays=@(),
    [switch]$OperatorTestFilterConfirmed,
    [switch]$VerifyStripe
)
$ErrorActionPreference='Stop'
if($HostAlias -notmatch '^[A-Za-z0-9][A-Za-z0-9.-]+$'){throw 'Ungültiger SSH-Hostalias.'}
$statisticsRepository=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$statisticsOutput=[IO.Path]::GetFullPath($OutputDirectory)
if($statisticsOutput.StartsWith($statisticsRepository+[IO.Path]::DirectorySeparatorChar,[StringComparison]::OrdinalIgnoreCase) -or $statisticsOutput -eq $statisticsRepository){throw 'Echte Laufzeitdaten müssen außerhalb des Repositorys liegen.'}
$statisticsExistingPolicy=Join-Path $statisticsOutput 'statistics-live-origin.json'
if(-not $PSBoundParameters.ContainsKey('ExcludedConfirmationDays') -and (Test-Path -LiteralPath $statisticsExistingPolicy)){
    $statisticsPolicy=Get-Content -LiteralPath $statisticsExistingPolicy -Raw|ConvertFrom-Json -Depth 80
    if($statisticsPolicy.kind -ne 'origin' -or $statisticsPolicy.environment -ne 'live'){throw 'Gespeicherte Ausschlusspolitik ist nicht lesbar.'}
    if($null -ne $statisticsPolicy.commerce.excludedConfirmationDays){$ExcludedConfirmationDays=@($statisticsPolicy.commerce.excludedConfirmationDays)}
    if(-not $PSBoundParameters.ContainsKey('OperatorTestFilterConfirmed')){$OperatorTestFilterConfirmed=[switch]($statisticsPolicy.commerce.operatorTestFilterConfirmed -eq $true)}
}
foreach($statisticsDate in $ExcludedConfirmationDays){
    $statisticsParsedDate=[datetime]::MinValue
    if(-not [datetime]::TryParseExact($statisticsDate,'yyyy-MM-dd',[Globalization.CultureInfo]::InvariantCulture,[Globalization.DateTimeStyles]::None,[ref]$statisticsParsedDate)){throw 'Ungültiger Ausschlusstag.'}
}
$ExcludedConfirmationDays=@($ExcludedConfirmationDays|Sort-Object -Unique)
$statisticsOptions=@{excludedConfirmationDays=@($ExcludedConfirmationDays);operatorTestFilterConfirmed=$OperatorTestFilterConfirmed.IsPresent;verifyStripe=$VerifyStripe.IsPresent}|ConvertTo-Json -Compress
$statisticsEncoded=[Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($statisticsOptions))
$statisticsLibrary=(Get-Content -LiteralPath (Join-Path $PSScriptRoot '../hosting/statistik/commerce-projection.php') -Raw).Replace('<?php','').Replace('declare(strict_types=1);','')
$statisticsReader=(Get-Content -LiteralPath (Join-Path $PSScriptRoot 'statistics-source-reader.php') -Raw).Replace('<?php','').Replace('declare(strict_types=1);','')
$statisticsCode="<?php`ndeclare(strict_types=1);`n"+$statisticsLibrary+"`ndefine('CARMAJA_STATISTICS_READ_OPTIONS',json_decode(base64_decode('"+$statisticsEncoded+"'),true,16,JSON_THROW_ON_ERROR));`n"+$statisticsReader
try {
$statisticsResult=$statisticsCode | ssh.exe -T -o BatchMode=yes -o PasswordAuthentication=no -o KbdInteractiveAuthentication=no -o StrictHostKeyChecking=yes -o UpdateHostKeys=no $HostAlias '/usr/bin/php8.4 -d display_errors=0 -d log_errors=0'
if($LASTEXITCODE -ne 0){
    $statisticsStage='Transport oder PHP-Start'
    try{$statisticsFailure=$statisticsResult|ConvertFrom-Json;if($statisticsFailure.stage -match '^[a-z_]+$'){$statisticsStage=$statisticsFailure.stage};if($statisticsFailure.reason -match '^[a-z_]+$'){$statisticsStage+=' / '+$statisticsFailure.reason}}catch{}
    throw ('Lesender Quellenabruf fehlgeschlagen: '+$statisticsStage+'. Kein lokaler Auszug wurde ersetzt.')
}
$statisticsPacket=$statisticsResult|ConvertFrom-Json -Depth 80
function Assert-StatisticsSafeFields($Value){
    if($null -eq $Value -or $Value -is [string] -or $Value -is [ValueType]){return}
    if($Value -is [System.Collections.IEnumerable] -and $Value -isnot [pscustomobject]){foreach($statisticsPart in $Value){Assert-StatisticsSafeFields $statisticsPart};return}
    foreach($statisticsProperty in $Value.PSObject.Properties){
        if($statisticsProperty.Name -match '(?i)password|secret|token|authorization|private.?key|commerceDsn|commerceUser|^customer|^email$|^shipping_address$|^billing_address$|^session_hash$|^(order|payment|checkout)_id$|^stripe_.*_id$'){
            throw 'Quellenpaket enthält ein nicht freigegebenes privates Feld.'
        }
        Assert-StatisticsSafeFields $statisticsProperty.Value
    }
}
Assert-StatisticsSafeFields $statisticsPacket
if($statisticsPacket.status -ne 'ok' -or $statisticsPacket.commerce.version -ne 1 -or $statisticsPacket.commerce.kind -ne 'commerce' -or $statisticsPacket.commerce.environment -ne 'live' -or $statisticsPacket.website.version -ne 3 -or $statisticsPacket.metadata.kind -ne 'origin' -or $statisticsPacket.metadata.environment -ne 'live' -or $statisticsPacket.metadata.verification.tlsActive -ne $true -or $statisticsPacket.metadata.verification.serverFilesWritten -ne $false){throw 'Quellenpaket ist ungültig; vorherige Auszüge bleiben erhalten.'}
$statisticsReceivedDates=@($statisticsPacket.metadata.commerce.excludedConfirmationDays|Where-Object {$_ -is [string]}|Sort-Object -Unique)
if(($statisticsReceivedDates -join ',') -ne ($ExcludedConfirmationDays -join ',') -or ($statisticsPacket.metadata.commerce.operatorTestFilterConfirmed -eq $true) -ne $OperatorTestFilterConfirmed.IsPresent -or ($statisticsPacket.commerce.operatorTestFilterConfirmed -eq $true) -ne $OperatorTestFilterConfirmed.IsPresent){throw 'Bestätigte Testausschlüsse stimmen nicht; vorherige Auszüge bleiben erhalten.'}
foreach($statisticsPeriod in @('current','previous','allAvailable')){
    $statisticsCheck=$statisticsPacket.metadata.verification.commerce.$statisticsPeriod
    if($statisticsCheck.matches -ne $true -or $null -eq $statisticsCheck.source -or ($statisticsCheck.source|ConvertTo-Json -Compress -Depth 10) -ne ($statisticsCheck.projection|ConvertTo-Json -Compress -Depth 10)){throw 'Commerce-Abgleich stimmt nicht; vorherige Auszüge bleiben erhalten.'}
}
foreach($statisticsPeriod in @('current','previous')){
    $statisticsCheck=$statisticsPacket.metadata.verification.website.$statisticsPeriod
    if($statisticsCheck.matches -ne $true -or $null -eq $statisticsCheck.sourceViews -or $null -eq $statisticsCheck.sourceEntryViews -or $statisticsCheck.sourceViews -ne $statisticsCheck.copiedViews -or $statisticsCheck.sourceEntryViews -ne $statisticsCheck.copiedEntryViews){throw 'Website-Abgleich stimmt nicht; vorherige Auszüge bleiben erhalten.'}
}
$statisticsStripe=$statisticsPacket.metadata.verification.stripe
if($VerifyStripe.IsPresent -or $statisticsStripe.requested -eq $true -or $statisticsStripe.checkedPayments -gt 0){
    if($statisticsStripe.requested -ne $true -or $null -eq $statisticsStripe.records -or $statisticsStripe.checkedPayments -ne @($statisticsStripe.records).Count -or $statisticsStripe.checkedPayments -ne $statisticsPacket.metadata.verification.commerce.allAvailable.source.orders){throw 'Stripe-Abgleich ist unvollständig; vorherige Auszüge bleiben erhalten.'}
    $statisticsPaid=0
    foreach($statisticsPayment in $statisticsStripe.records){if($statisticsPayment.matches -ne $true -or $statisticsPayment.currency -ne 'eur'){throw 'Stripe-Abgleich stimmt nicht; vorherige Auszüge bleiben erhalten.'};$statisticsPaid+=$statisticsPayment.paidMinor}
    if($statisticsPaid -ne $statisticsPacket.metadata.verification.commerce.allAvailable.source.paidTotalMinor){throw 'Stripe-Summen stimmen nicht; vorherige Auszüge bleiben erhalten.'}
}
# The transmitted envelope contains only allowlisted aggregate fields, never runtime credentials or customer records.
if(-not (Test-Path -LiteralPath $statisticsOutput)){New-Item -ItemType Directory -Path $statisticsOutput|Out-Null}
$statisticsMappings=@{'statistics-live-commerce.json'=$statisticsPacket.commerce;'statistics-live-views.json'=$statisticsPacket.website;'statistics-live-origin.json'=$statisticsPacket.metadata}
foreach($statisticsName in $statisticsMappings.Keys){
    $statisticsDestination=Join-Path $statisticsOutput $statisticsName
    $statisticsTemporary=$statisticsDestination+'.new'
    $statisticsJson=$statisticsMappings[$statisticsName]|ConvertTo-Json -Depth 80 -Compress
    [IO.File]::WriteAllText($statisticsTemporary,$statisticsJson,[Text.UTF8Encoding]::new($false))
    Get-Content -LiteralPath $statisticsTemporary -Raw|ConvertFrom-Json -Depth 80|Out-Null
    Move-Item -LiteralPath $statisticsTemporary -Destination $statisticsDestination -Force
}
foreach($statisticsName in $statisticsMappings.Keys){$statisticsStatus=Join-Path $statisticsOutput ($statisticsName+'.status.json');if(Test-Path -LiteralPath $statisticsStatus){Remove-Item -LiteralPath $statisticsStatus -Force}}
[pscustomobject]@{status='ok';readAt=$statisticsPacket.metadata.generatedAt;serverFilesWritten=$false;verification=$statisticsPacket.metadata.verification}|ConvertTo-Json -Depth 15
} catch {
    # Signal a failed local refresh without storing the error text, raw response or connection data.
    if(Test-Path -LiteralPath $statisticsOutput){
        $statisticsFailureState=@{status='error';generatedAt=[DateTimeOffset]::UtcNow.ToString("yyyy-MM-dd'T'HH:mm:ss.ffffffzzz")}|ConvertTo-Json -Compress
        foreach($statisticsName in @('statistics-live-commerce.json','statistics-live-views.json','statistics-live-origin.json')){
            $statisticsExisting=Join-Path $statisticsOutput $statisticsName
            if(Test-Path -LiteralPath $statisticsExisting){try{[IO.File]::WriteAllText($statisticsExisting+'.status.json',$statisticsFailureState,[Text.UTF8Encoding]::new($false))}catch{}}
        }
    }
    throw
}
