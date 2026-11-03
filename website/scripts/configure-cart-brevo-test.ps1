[CmdletBinding()]
param([switch]$SelfTest)
$ErrorActionPreference='Stop'

function ConvertTo-CartBrevoTestInput([hashtable]$Values) {
    $fields=@('brevoApiKey','brevoSenderEmail','brevoSenderName','testRecipientEmail','testRecipientConfirmed')
    if ($Values.Count -ne 5 -or @($Values.Keys | Where-Object { $_ -notin $fields }).Count -ne 0 -or $Values.testRecipientConfirmed -ne $true) { throw 'TEST_INPUT_INVALID' }
    $normalized=[ordered]@{}
    foreach ($field in $fields[0..3]) {
        if ($Values[$field] -isnot [string]) { throw 'TEST_INPUT_INVALID' }
        $normalized[$field]=$Values[$field].Trim()
    }
    if ($normalized.brevoApiKey -notmatch '^[!-~]{16,4096}$' -or $normalized.brevoApiKey -match '[\r\n]') { throw 'TEST_INPUT_INVALID' }
    foreach ($field in @('brevoSenderEmail','testRecipientEmail')) {
        $email=$normalized[$field]
        if ($email.Length -gt 254 -or $email -match '[\s\r\n]') { throw 'TEST_INPUT_INVALID' }
        try { $address=[System.Net.Mail.MailAddress]::new($email) } catch { throw 'TEST_INPUT_INVALID' }
        if ($address.Address -ne $email -or $email -notmatch '@[^@]+\.') { throw 'TEST_INPUT_INVALID' }
    }
    if ($normalized.brevoSenderName.Length -eq 0 -or [Text.Encoding]::UTF8.GetByteCount($normalized.brevoSenderName) -gt 200 -or $normalized.brevoSenderName -match '[\x00-\x1F\x7F]') { throw 'TEST_INPUT_INVALID' }
    $normalized.testRecipientConfirmed=$true
    return $normalized | ConvertTo-Json -Compress
}

function Send-CartBrevoTestInput([string]$Json) {
    $program=[IO.File]::ReadAllText((Join-Path $PSScriptRoot 'install-cart-brevo-test.php')) -replace '^<\?php\s*',''
    $encoded=[Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($program))
    $command='/usr/bin/php8.4 -r "eval(base64_decode(' + "'" + $encoded + "'" + '));"'
    $start=[Diagnostics.ProcessStartInfo]::new(); $start.FileName=(Get-Command ssh.exe -ErrorAction Stop).Source
    $start.Arguments='-T -o BatchMode=yes -o StrictHostKeyChecking=yes -o PreferredAuthentications=publickey -o PasswordAuthentication=no -o ConnectTimeout=10 carmaja-test-ionos "' + $command.Replace('"','\"') + '"'
    $start.UseShellExecute=$false; $start.CreateNoWindow=$true
    $start.RedirectStandardInput=$true; $start.RedirectStandardOutput=$true; $start.RedirectStandardError=$true
    $process=[Diagnostics.Process]::new(); $process.StartInfo=$start; $bytes=$null
    try {
        if (!$process.Start()) { throw 'SSH_START_FAILED' }
        $stdout=$process.StandardOutput.ReadToEndAsync(); $stderr=$process.StandardError.ReadToEndAsync()
        $bytes=[Text.Encoding]::UTF8.GetBytes($Json)
        $process.StandardInput.BaseStream.Write($bytes,0,$bytes.Length); $process.StandardInput.BaseStream.Flush(); $process.StandardInput.Close()
        if (!$process.WaitForExit(45000)) { $process.Kill(); throw 'SSH_TIMEOUT' }
        $output=$stdout.GetAwaiter().GetResult().Trim(); $null=$stderr.GetAwaiter().GetResult()
        if ($process.ExitCode -ne 0 -or $output -ne 'CART_BREVO_TEST_CONFIG_CREATED_0600') { throw 'PRIVATE_INSTALL_FAILED' }
    } finally { $process.Dispose(); if ($null -ne $bytes) { [Array]::Clear($bytes,0,$bytes.Length) }; $Json=$null }
}

if ($SelfTest) {
    $fake=@{brevoApiKey=('x'*32);brevoSenderEmail='sender@example.invalid';brevoSenderName='Cart Sandbox';testRecipientEmail='recipient@example.invalid';testRecipientConfirmed=$true}
    $result=(ConvertTo-CartBrevoTestInput $fake) | ConvertFrom-Json
    if ($result.brevoSenderName -ne 'Cart Sandbox' -or !$result.testRecipientConfirmed) { throw 'SELFTEST_FAILED' }
    foreach ($bad in @(@{brevoApiKey='';testRecipientConfirmed=$true},@{brevoSenderEmail="a@example.invalid`nb@example.invalid";testRecipientConfirmed=$true},@{testRecipientConfirmed=$false})) {
        $candidate=$fake.Clone(); foreach($key in $bad.Keys){$candidate[$key]=$bad[$key]}
        $rejected=$false; try {$null=ConvertTo-CartBrevoTestInput $candidate} catch {$rejected=$true}
        if (!$rejected) {throw 'SELFTEST_REJECTION_FAILED'}
    }
    Write-Output 'PASS Brevo input guards; no SSH, secrets or writes.'; exit 0
}
if ([Threading.Thread]::CurrentThread.ApartmentState -ne 'STA') { throw 'Mit powershell.exe -STA starten.' }
Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing
$form=[Windows.Forms.Form]::new(); $form.Text='Carmaja - Brevo-Warenkorbtest'; $form.StartPosition='CenterScreen'; $form.TopMost=$true
$form.FormBorderStyle='FixedDialog'; $form.MaximizeBox=$false; $form.MinimizeBox=$false; $form.ClientSize=[Drawing.Size]::new(760,365)
$intro=[Windows.Forms.Label]::new(); $intro.Location=[Drawing.Point]::new(16,12); $intro.Size=[Drawing.Size]::new(724,53)
$intro.Text='Nur Brevo-Testzugang und eigene Testempfaengeradresse. Keine Produktionskonfiguration. Eingaben gehen per SSH in eine private 0600-Datei; noch kein Mailversand.'; $form.Controls.Add($intro)
$definitions=@(@('brevoApiKey','Brevo-Test-API-Key'),@('brevoSenderEmail','Verifizierte Absender-E-Mail'),@('brevoSenderName','Absendername'),@('testRecipientEmail','Eigene Testempfaenger-E-Mail'))
$boxes=@{}; $index=0
foreach($definition in $definitions) {
    $label=[Windows.Forms.Label]::new(); $label.Text=$definition[1]; $label.Location=[Drawing.Point]::new(16,(80+40*$index)); $label.Size=[Drawing.Size]::new(210,24); $form.Controls.Add($label)
    $box=[Windows.Forms.TextBox]::new(); $box.Location=[Drawing.Point]::new(232,(76+40*$index)); $box.Size=[Drawing.Size]::new(394,26); $box.ShortcutsEnabled=$true
    $box.UseSystemPasswordChar=($index -eq 0); $box.MaxLength=4096
    if($definition[0] -eq 'brevoSenderName'){$box.Text='Carmaja-Perlen Warenkorbtest'}
    $boxes[$definition[0]]=$box; $form.Controls.Add($box)
    $paste=[Windows.Forms.Button]::new(); $paste.Text='Einfuegen'; $paste.Location=[Drawing.Point]::new(637,(74+40*$index)); $paste.Size=[Drawing.Size]::new(105,28); $paste.Tag=$box
    $paste.Add_Click({param($sender,$eventArgs) $sender.Tag.Text=[Windows.Forms.Clipboard]::GetText().Trim(); [Windows.Forms.Clipboard]::Clear()}); $form.Controls.Add($paste); $index++
}
$confirmed=[Windows.Forms.CheckBox]::new(); $confirmed.Text='Diese Empfaengeradresse gehoert mir und darf ausschliesslich Testmails erhalten.'
$confirmed.Location=[Drawing.Point]::new(16,240); $confirmed.Size=[Drawing.Size]::new(724,28); $form.Controls.Add($confirmed)
$status=[Windows.Forms.Label]::new(); $status.Location=[Drawing.Point]::new(16,272); $status.Size=[Drawing.Size]::new(724,44); $status.Text='Keine Geheimwerte im Chat, Repository oder lokalen Dateien.'; $form.Controls.Add($status)
$save=[Windows.Forms.Button]::new(); $save.Text='Sicher bereitstellen'; $save.Location=[Drawing.Point]::new(444,320); $save.Size=[Drawing.Size]::new(175,30)
$cancel=[Windows.Forms.Button]::new(); $cancel.Text='Abbrechen'; $cancel.Location=[Drawing.Point]::new(634,320); $cancel.Size=[Drawing.Size]::new(110,30); $cancel.DialogResult='Cancel'
$form.Tag=@{Boxes=$boxes;Confirmed=$confirmed;Status=$status;Save=$save;Cancel=$cancel}
$save.Add_Click({
    param($sender,$eventArgs)
    $dialog=$sender.FindForm(); $state=$dialog.Tag; $values=@{testRecipientConfirmed=[bool]$state.Confirmed.Checked}; $json=$null
    foreach($key in $state.Boxes.Keys){$values[$key]=[string]$state.Boxes[$key].Text}
    try {$json=ConvertTo-CartBrevoTestInput $values} catch {$state.Status.Text='Alle vier Felder und die Testempfaenger-Bestaetigung pruefen. Keine Werte in den Chat kopieren.'; $values.Clear(); return}
    $values.Clear(); $state.Save.Enabled=$false; $state.Cancel.Enabled=$false; $state.Status.Text='Private SSH-Uebertragung ...'; $dialog.Refresh()
    try {
        Send-CartBrevoTestInput $json
        foreach($box in $state.Boxes.Values){$box.Clear()}; [Windows.Forms.Clipboard]::Clear()
        [Windows.Forms.MessageBox]::Show('Brevo-Testkonfiguration sicher bereitgestellt. Noch keine Mail versendet.','Carmaja','OK','Information') | Out-Null
        $dialog.DialogResult='OK'; $dialog.Close()
    } catch {$state.Status.Text='Bereitstellung fehlgeschlagen. Bestehende Datei bleibt erhalten. Codex informieren, keine Geheimwerte kopieren.'; $state.Save.Enabled=$true; $state.Cancel.Enabled=$true}
    finally {$json=$null}
})
$form.Controls.Add($save); $form.Controls.Add($cancel); $form.AcceptButton=$save; $form.CancelButton=$cancel
try {$null=$form.ShowDialog()} finally {foreach($box in $boxes.Values){$box.Clear()}; [Windows.Forms.Clipboard]::Clear(); $form.Dispose()}
