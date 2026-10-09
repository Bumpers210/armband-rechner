[CmdletBinding()]
param([switch]$SelfTest)

$ErrorActionPreference = 'Stop'

function ConvertTo-CartTestConfiguration([hashtable]$Values) {
    $targets = @{}
    foreach ($label in @('Source', 'Restore')) {
        $target = [ordered]@{}
        foreach ($field in @('Host', 'User', 'Password')) {
            $value = [string]$Values[($label + $field)]
            if ($field -ne 'Password') { $value = $value.Trim() }
            if ([string]::IsNullOrEmpty($value) -or $value.Length -gt 4096 -or $value.IndexOfAny([char[]]"`r`n`0") -ge 0) {
                throw ('Bitte das Feld {0} {1} pruefen. Keine mehrzeiligen Werte.' -f $label, $field)
            }
            if ($field -eq 'Host' -and $value -notmatch '^[a-zA-Z0-9][a-zA-Z0-9.-]{0,252}$') { throw ('Bitte {0} Host ohne URL oder Leerzeichen eingeben.' -f $label) }
            if ($field -eq 'User' -and $value -notmatch '^[a-zA-Z0-9_-]{1,128}$') { throw ('Bitte {0} Benutzer pruefen.' -f $label) }
            $target[$field] = $value
        }
        $targets[$label] = [ordered]@{
            host = $target.Host; port = 3306
            database = $(if ($label -eq 'Source') { 'dbs15962244' } else { 'dbs15962566' })
            username = $target.User; password = $target.Password
        }
    }
    if ($targets.Source.username -eq $targets.Restore.username) { throw 'Source und Restore brauchen unterschiedliche zugehoerige Benutzer.' }
    return [ordered]@{testOnly = $true; requireTls = $true; acceptUnverifiedTls = $true; tlsCaPath = ''; source = $targets.Source; restore = $targets.Restore}
}

function Send-CartTestConfiguration([string]$Json) {
    # Only non-secret installer program text is encoded into the remote command.
    # Configuration values travel exclusively through the encrypted SSH stdin.
    $program = [IO.File]::ReadAllText((Join-Path $PSScriptRoot 'install-cart-test-config.php'))
    $program = $program -replace '^<\?php\s*', ''
    $encodedProgram = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($program))
    $command = '/usr/bin/php8.4 -r "eval(base64_decode(' + "'" + $encodedProgram + "'" + '));"'
    $start = [Diagnostics.ProcessStartInfo]::new()
    $start.FileName = (Get-Command ssh.exe -ErrorAction Stop).Source
    $start.Arguments = '-T -o BatchMode=yes -o StrictHostKeyChecking=yes -o PreferredAuthentications=publickey -o PasswordAuthentication=no -o ConnectTimeout=10 carmaja-test-ionos "' + $command.Replace('"', '\"') + '"'
    $start.UseShellExecute = $false
    $start.CreateNoWindow = $true
    $start.RedirectStandardInput = $true
    $start.RedirectStandardOutput = $true
    $start.RedirectStandardError = $true
    $process = [Diagnostics.Process]::new()
    $process.StartInfo = $start
    try {
        if (!$process.Start()) { throw 'SSH_START_FAILED' }
        $stdout = $process.StandardOutput.ReadToEndAsync()
        $stderr = $process.StandardError.ReadToEndAsync()
        $bytes = [Text.Encoding]::UTF8.GetBytes($Json)
        $process.StandardInput.BaseStream.Write($bytes, 0, $bytes.Length)
        $process.StandardInput.BaseStream.Flush()
        $process.StandardInput.Close()
        if (!$process.WaitForExit(45000)) { $process.Kill(); throw 'SSH_TIMEOUT' }
        $output = $stdout.GetAwaiter().GetResult().Trim()
        $errors = $stderr.GetAwaiter().GetResult()
        if ($process.ExitCode -ne 0 -or $output -ne 'CART_TEST_CONFIG_CREATED_0600') {
            if ($errors -match '(?m)^CONFIG_EXISTS(?:_OR_LINK_FAILED)?\r?$') {
                throw 'Die private Konfiguration existiert bereits oder konnte nicht atomar angelegt werden. Nichts wurde ueberschrieben.'
            }
            throw 'SSH oder sichere Bereitstellung fehlgeschlagen. Keine Zugangsdaten wurden ausgegeben; Eingaben bleiben fuer eine Korrektur im Fenster.'
        }
    } finally {
        $process.Dispose()
        if ($null -ne $bytes) { [Array]::Clear($bytes, 0, $bytes.Length) }
        $Json = $null
        $errors = $null
    }
}

if ($SelfTest) {
    $values = @{SourceHost=" db-source.example.invalid`r`n";SourceUser='source_test';SourcePassword=' synthetic $pwd; ';
        RestoreHost='db-restore.example.invalid';RestoreUser='restore_test';RestorePassword='synthetic-other'}
    $config = ConvertTo-CartTestConfiguration $values
    if ($config.source.host -ne 'db-source.example.invalid' -or $config.source.password -ne $values.SourcePassword -or
        $config.source.database -ne 'dbs15962244' -or $config.restore.database -ne 'dbs15962566') { throw 'SELF_TEST_FAILED' }
    $roundTrip = ($config | ConvertTo-Json -Depth 6) | ConvertFrom-Json
    if ($roundTrip.source.password -ne $values.SourcePassword) { throw 'JSON_SELF_TEST_FAILED' }
    foreach ($case in @('empty', 'linebreak', 'same_user')) {
        $bad = $values.Clone()
        if ($case -eq 'empty') { $bad.SourceHost = '' }
        if ($case -eq 'linebreak') { $bad.SourcePassword = "first`nsecond" }
        if ($case -eq 'same_user') { $bad.RestoreUser = $bad.SourceUser }
        $rejected = $false
        try { $null = ConvertTo-CartTestConfiguration $bad } catch { $rejected = $true }
        if (!$rejected) { throw 'REJECTION_SELF_TEST_FAILED' }
    }
    Write-Output 'PASS: input normalization, password preservation, JSON, invalid fields, separate users; no SSH or writes.'
    exit 0
}

if ([Threading.Thread]::CurrentThread.ApartmentState -ne 'STA') { throw 'Bitte das Skript mit powershell.exe -STA starten.' }
Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing
$form = [Windows.Forms.Form]::new()
$form.Text = 'Carmaja - Warenkorb-Testdatenbanken'
$form.StartPosition = 'CenterScreen'
$form.FormBorderStyle = 'FixedDialog'
$form.MaximizeBox = $false
$form.MinimizeBox = $false
$form.TopMost = $true
$form.ClientSize = [Drawing.Size]::new(680, 510)
$form.AutoScaleMode = 'Dpi'
$intro = [Windows.Forms.Label]::new()
$intro.Location = [Drawing.Point]::new(16, 12)
$intro.Size = [Drawing.Size]::new(645, 50)
$intro.Text = 'Nur die beiden bestehenden AP0-C.2-Testdatenbanken. Jeweils Host, Benutzer und Passwort aus demselben IONOS-Datensatz. Keine Produktionsdaten und kein Testlauf.'
$form.Controls.Add($intro)
$definitions = @(
    @{Key='SourceHost';Label='Quelle: Host'}, @{Key='SourceDatabase';Label='Quelle: Datenbank';Fixed='dbs15962244'},
    @{Key='SourceUser';Label='Quelle: Benutzer'}, @{Key='SourcePassword';Label='Quelle: Passwort';Secret=$true},
    @{Key='RestoreHost';Label='Restore: Host'}, @{Key='RestoreDatabase';Label='Restore: Datenbank';Fixed='dbs15962566'},
    @{Key='RestoreUser';Label='Restore: Benutzer'}, @{Key='RestorePassword';Label='Restore: Passwort';Secret=$true}
)
$controls = @{}
for ($i=0; $i -lt $definitions.Count; $i++) {
    $definition = $definitions[$i]
    $y = 68 + 35 * $i
    $label = [Windows.Forms.Label]::new()
    $label.Location = [Drawing.Point]::new(16, $y + 4)
    $label.Size = [Drawing.Size]::new(160, 24)
    $label.Text = $definition.Label
    $form.Controls.Add($label)
    $box = [Windows.Forms.TextBox]::new()
    $box.Location = [Drawing.Point]::new(180, $y)
    $box.Size = [Drawing.Size]::new(365, 25)
    $box.ShortcutsEnabled = $true
    $box.UseSystemPasswordChar = [bool]$definition.Secret
    $box.MaxLength = 4096
    if ($definition.Fixed) { $box.Text = $definition.Fixed; $box.ReadOnly = $true }
    $form.Controls.Add($box)
    $controls[$definition.Key] = $box
    if (!$definition.Fixed) {
        $paste = [Windows.Forms.Button]::new()
        $paste.Text = 'Einfuegen'
        $paste.Location = [Drawing.Point]::new(556, $y - 1)
        $paste.Size = [Drawing.Size]::new(103, 27)
        $paste.Tag = $box
        $paste.Add_Click({
            param($sender, $eventArgs)
            $text = [Windows.Forms.Clipboard]::GetText()
            if (![string]::IsNullOrEmpty($text)) {
                $sender.Tag.Text = $text.TrimEnd([char[]]"`r`n")
                [Windows.Forms.Clipboard]::Clear()
            }
        })
        $form.Controls.Add($paste)
    }
}
$confirm = [Windows.Forms.CheckBox]::new()
$confirm.Location = [Drawing.Point]::new(16, 354)
$confirm.Size = [Drawing.Size]::new(640, 45)
$confirm.Text = 'Nur isolierte Testziele. Aktive TLS-Verschluesselung bleibt Pflicht; fehlende CA-/Hostidentitaetspruefung akzeptiere ich als Test-Restrisiko.'
$form.Controls.Add($confirm)
$status = [Windows.Forms.Label]::new()
$status.Location = [Drawing.Point]::new(16, 402)
$status.Size = [Drawing.Size]::new(640, 44)
$status.Text = 'Speicherung nur auf IONOS, privat mit 0600. Lokal keine Zugangsdaten-Datei.'
$form.Controls.Add($status)
$ok = [Windows.Forms.Button]::new()
$ok.Location = [Drawing.Point]::new(382, 462)
$ok.Size = [Drawing.Size]::new(168, 30)
$ok.Text = 'Sicher bereitstellen'
$cancel = [Windows.Forms.Button]::new()
$cancel.Location = [Drawing.Point]::new(560, 462)
$cancel.Size = [Drawing.Size]::new(100, 30)
$cancel.Text = 'Abbrechen'
$cancel.DialogResult = [Windows.Forms.DialogResult]::Cancel
$form.Tag = @{Controls=$controls;Definitions=$definitions;Confirm=$confirm;Status=$status;Ok=$ok;Cancel=$cancel}
$ok.Add_Click({
    param($sender, $eventArgs)
    $dialog = $sender.FindForm()
    $state = $dialog.Tag
    if (!$state.Confirm.Checked) { $state.Status.Text = 'Bitte zuerst die Test-/TLS-Bestaetigung ankreuzen.'; return }
    $values = @{}
    # Read actual Text, not a cached Tag value. Normal Ctrl+V works as well.
    foreach ($definition in $state.Definitions) { $values[$definition.Key] = [string]$state.Controls[$definition.Key].Text }
    try { $config = ConvertTo-CartTestConfiguration $values }
    catch { $state.Status.Text = $_.Exception.Message; return }
    $state.Ok.Enabled = $false
    $state.Cancel.Enabled = $false
    $state.Status.Text = 'Sichere SSH-Uebertragung laeuft ...'
    $dialog.Refresh()
    try {
        $json = $config | ConvertTo-Json -Depth 6 -Compress
        Send-CartTestConfiguration $json
        [Windows.Forms.Clipboard]::Clear()
        foreach ($box in $state.Controls.Values) { if (!$box.ReadOnly) { $box.Clear() } }
        [Windows.Forms.MessageBox]::Show('Warenkorb-Testkonfiguration sicher bereitgestellt und Modus 0600 geprueft. Keine Datenbanktests gestartet.', 'Carmaja', 'OK', 'Information') | Out-Null
        $dialog.DialogResult = [Windows.Forms.DialogResult]::OK
        $dialog.Close()
    } catch {
        # Never display a raw exception, stdin, remote response or credentials.
        $state.Status.Text = 'Bereitstellung fehlgeschlagen. Bestehende Dateien werden nicht ueberschrieben. Bitte Codex informieren; Eingaben bleiben im Fenster.'
        $state.Ok.Enabled = $true
        $state.Cancel.Enabled = $true
    } finally { $json = $null; $config = $null; $values.Clear() }
})
$form.Controls.Add($ok)
$form.Controls.Add($cancel)
$form.AcceptButton = $ok
$form.CancelButton = $cancel
try { $null = $form.ShowDialog() }
finally {
    foreach ($box in $controls.Values) { if (!$box.ReadOnly) { $box.Clear() } }
    [Windows.Forms.Clipboard]::Clear()
    $form.Dispose()
}
