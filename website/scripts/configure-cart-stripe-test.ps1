[CmdletBinding()]
param([switch]$SelfTest)
$ErrorActionPreference='Stop'

function ConvertTo-CartStripeTestInput([string]$Value) {
    $value=$Value.Trim()
    if ($value -notmatch '^sk_test_[a-zA-Z0-9]{16,4096}$') { throw 'Bitte einen Stripe-Sandbox-Secret-Key mit sk_test_ einfuegen. Kein Live-Key.' }
    return @{stripeSecretKey=$value} | ConvertTo-Json -Compress
}

function Send-CartStripeTestInput([string]$Json) {
    $program=[IO.File]::ReadAllText((Join-Path $PSScriptRoot 'install-cart-stripe-test.php')) -replace '^<\?php\s*',''
    $encoded=[Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($program))
    $command='/usr/bin/php8.4 -r "eval(base64_decode(' + "'" + $encoded + "'" + '));"'
    $start=[Diagnostics.ProcessStartInfo]::new()
    $start.FileName=(Get-Command ssh.exe -ErrorAction Stop).Source
    $start.Arguments='-T -o BatchMode=yes -o StrictHostKeyChecking=yes -o PreferredAuthentications=publickey -o PasswordAuthentication=no -o ConnectTimeout=10 carmaja-test-ionos "' + $command.Replace('"','\"') + '"'
    $start.UseShellExecute=$false; $start.CreateNoWindow=$true
    $start.RedirectStandardInput=$true; $start.RedirectStandardOutput=$true; $start.RedirectStandardError=$true
    $process=[Diagnostics.Process]::new(); $process.StartInfo=$start; $bytes=$null
    try {
        if (!$process.Start()) { throw 'SSH_START_FAILED' }
        $stdout=$process.StandardOutput.ReadToEndAsync(); $stderr=$process.StandardError.ReadToEndAsync()
        $bytes=[Text.Encoding]::UTF8.GetBytes($Json)
        $process.StandardInput.BaseStream.Write($bytes,0,$bytes.Length)
        $process.StandardInput.BaseStream.Flush(); $process.StandardInput.Close()
        if (!$process.WaitForExit(45000)) { $process.Kill(); throw 'SSH_TIMEOUT' }
        $output=$stdout.GetAwaiter().GetResult().Trim(); $null=$stderr.GetAwaiter().GetResult()
        if ($process.ExitCode -ne 0 -or $output -ne 'CART_STRIPE_TEST_CONFIG_CREATED_0600') { throw 'PRIVATE_INSTALL_FAILED' }
    } finally { $process.Dispose(); if ($null -ne $bytes) { [Array]::Clear($bytes,0,$bytes.Length) }; $Json=$null }
}

if ($SelfTest) {
    $fake='sk_test_' + ('x'*32)
    $result=(ConvertTo-CartStripeTestInput (" `r`n"+$fake+"`r`n")) | ConvertFrom-Json
    if ($result.stripeSecretKey -ne $fake) { throw 'SELFTEST_FAILED' }
    foreach ($bad in @('',('sk_live_'+('x'*32)),('pk_test_'+('x'*32)),("sk_test_"+('x'*16)+"`n"+('x'*16)))) {
        $rejected=$false; try { $null=ConvertTo-CartStripeTestInput $bad } catch { $rejected=$true }
        if (!$rejected) { throw 'SELFTEST_REJECTION_FAILED' }
    }
    Write-Output 'PASS Stripe input normalization and test-only rejection; no SSH, secrets or writes.'
    exit 0
}
if ([Threading.Thread]::CurrentThread.ApartmentState -ne 'STA') { throw 'Mit powershell.exe -STA starten.' }
Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing
$form=[Windows.Forms.Form]::new(); $form.Text='Carmaja - Stripe-Warenkorbtest'
$form.StartPosition='CenterScreen'; $form.TopMost=$true; $form.FormBorderStyle='FixedDialog'
$form.MaximizeBox=$false; $form.MinimizeBox=$false; $form.ClientSize=[Drawing.Size]::new(650,220)
$intro=[Windows.Forms.Label]::new()
$intro.Location=[Drawing.Point]::new(16,12); $intro.Size=[Drawing.Size]::new(615,55)
$intro.Text='Nur Stripe-Sandbox: geheimen Test-API-Schluessel (sk_test_) einfuegen. Er wird ausschliesslich per SSH privat mit 0600 gespeichert. Keine Zahlung und keine Live-Ressource wird angelegt.'
$form.Controls.Add($intro)
$box=[Windows.Forms.TextBox]::new(); $box.Location=[Drawing.Point]::new(16,78); $box.Size=[Drawing.Size]::new(494,26)
$box.UseSystemPasswordChar=$true; $box.ShortcutsEnabled=$true; $box.MaxLength=4104; $form.Controls.Add($box)
$paste=[Windows.Forms.Button]::new(); $paste.Text='Einfuegen'; $paste.Location=[Drawing.Point]::new(520,76); $paste.Size=[Drawing.Size]::new(110,28); $paste.Tag=$box
$paste.Add_Click({param($sender,$eventArgs) $sender.Tag.Text=[Windows.Forms.Clipboard]::GetText().Trim(); [Windows.Forms.Clipboard]::Clear()})
$form.Controls.Add($paste)
$status=[Windows.Forms.Label]::new(); $status.Location=[Drawing.Point]::new(16,118); $status.Size=[Drawing.Size]::new(615,42)
$status.Text='Keine Speicherung auf diesem PC, im Repository oder in Protokollen.'; $form.Controls.Add($status)
$save=[Windows.Forms.Button]::new(); $save.Text='Sicher bereitstellen'; $save.Location=[Drawing.Point]::new(345,173); $save.Size=[Drawing.Size]::new(175,30)
$cancel=[Windows.Forms.Button]::new(); $cancel.Text='Abbrechen'; $cancel.Location=[Drawing.Point]::new(530,173); $cancel.Size=[Drawing.Size]::new(100,30); $cancel.DialogResult='Cancel'
$form.Tag=@{Box=$box;Status=$status;Save=$save;Cancel=$cancel}
$save.Add_Click({
    param($sender,$eventArgs)
    $dialog=$sender.FindForm(); $state=$dialog.Tag; $json=$null
    try { $json=ConvertTo-CartStripeTestInput ([string]$state.Box.Text) }
    catch { $state.Status.Text='Bitte Sandbox-Secret-Key mit sk_test_ pruefen. Kein Live-Key.'; return }
    $state.Save.Enabled=$false; $state.Cancel.Enabled=$false; $state.Status.Text='Private SSH-Uebertragung ...'; $dialog.Refresh()
    try {
        Send-CartStripeTestInput $json; $state.Box.Clear(); [Windows.Forms.Clipboard]::Clear()
        [Windows.Forms.MessageBox]::Show('Stripe-Testschluessel sicher mit 0600 bereitgestellt. Noch kein Testcheckout angelegt.','Carmaja','OK','Information') | Out-Null
        $dialog.DialogResult='OK'; $dialog.Close()
    } catch {
        $state.Status.Text='Bereitstellung fehlgeschlagen. Bestehende Datei wird nicht ersetzt. Bitte Codex informieren; keine Fehlermeldung mit Schluessel kopieren.'
        $state.Save.Enabled=$true; $state.Cancel.Enabled=$true
    } finally { $json=$null }
})
$form.Controls.Add($save); $form.Controls.Add($cancel); $form.AcceptButton=$save; $form.CancelButton=$cancel
try { $null=$form.ShowDialog() } finally { $box.Clear(); [Windows.Forms.Clipboard]::Clear(); $form.Dispose() }
