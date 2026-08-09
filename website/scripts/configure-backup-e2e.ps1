[CmdletBinding()]
param(
    [string]$SshAlias = 'carmaja-test-ionos',
    [string]$RemoteConfig = 'carmaja-private-test/ap77-backup-e2e.php'
)

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Security
if ($SshAlias -notmatch '^[A-Za-z0-9._-]+$' -or $RemoteConfig -ne 'carmaja-private-test/ap77-backup-e2e.php') {
    throw 'Unsichere E2E-Zielkonfiguration.'
}

function Quote-Php([string]$Value) {
    return "'" + $Value.Replace('\', '\\').Replace("'", "\'") + "'"
}

function Read-BackupConfiguration {
    Add-Type -AssemblyName System.Windows.Forms
    Add-Type -AssemblyName System.Drawing

    $form = [Windows.Forms.Form]::new()
    $form.Text = 'Carmaja AP7.7a - IONOS-Testdatenbanken (Eingabe v3)'
    $form.StartPosition = 'CenterScreen'
    $form.FormBorderStyle = 'FixedDialog'
    $form.MaximizeBox = $false
    $form.MinimizeBox = $false
    $form.TopMost = $true
    $form.ClientSize = [Drawing.Size]::new(620, 390)
    $form.AutoScaleMode = 'Dpi'

    $intro = [Windows.Forms.Label]::new()
    $intro.Location = [Drawing.Point]::new(18, 14)
    $intro.Size = [Drawing.Size]::new(585, 42)
    $intro.Text = 'Trage die zwei unterschiedlichen, leeren IONOS-Testdatenbanken ein. Passwoerter bleiben verdeckt und werden nicht ausgegeben.'
    $form.Controls.Add($intro)

    $definitions = @(
        @{ Key = 'SourceHost'; Label = 'Source Host'; Password = $false },
        @{ Key = 'SourceDatabase'; Label = 'Source Datenbank'; Password = $false },
        @{ Key = 'SourceUser'; Label = 'Source Benutzer'; Password = $false },
        @{ Key = 'SourcePassword'; Label = 'Source Passwort'; Password = $true },
        @{ Key = 'RestoreHost'; Label = 'Restore Host'; Password = $false },
        @{ Key = 'RestoreDatabase'; Label = 'Restore Datenbank'; Password = $false },
        @{ Key = 'RestoreUser'; Label = 'Restore Benutzer'; Password = $false },
        @{ Key = 'RestorePassword'; Label = 'Restore Passwort'; Password = $true }
    )
    $controls = @{}
    for ($index = 0; $index -lt $definitions.Count; $index++) {
        $definition = $definitions[$index]
        $top = 62 + ($index * 34)
        $label = [Windows.Forms.Label]::new()
        $label.Location = [Drawing.Point]::new(18, $top + 4)
        $label.Size = [Drawing.Size]::new(165, 22)
        $label.Text = $definition.Label
        $form.Controls.Add($label)

        $textBox = [Windows.Forms.TextBox]::new()
        $textBox.Location = [Drawing.Point]::new(190, $top)
        $textBox.Size = [Drawing.Size]::new(325, 24)
        $textBox.UseSystemPasswordChar = [bool]$definition.Password
        $textBox.ShortcutsEnabled = $true
        $textBox.Tag = ''
        $textBox.Add_TextChanged({
            param($sender, $eventArgs)
            $sender.Tag = [string]$sender.Text
            $sender.BackColor = if ([string]::IsNullOrWhiteSpace([string]$sender.Text)) {
                [Drawing.Color]::White
            }
            else {
                [Drawing.Color]::Honeydew
            }
        })
        $form.Controls.Add($textBox)
        $controls[$definition.Key] = $textBox

        $paste = [Windows.Forms.Button]::new()
        $paste.Text = 'Einfuegen'
        $paste.Location = [Drawing.Point]::new(525, $top - 1)
        $paste.Size = [Drawing.Size]::new(75, 26)
        $paste.Tag = $textBox
        $paste.Add_Click({
            param($sender, $eventArgs)
            $clipboardValue = [Windows.Forms.Clipboard]::GetText()
            if ([string]::IsNullOrEmpty($clipboardValue)) {
                [Windows.Forms.MessageBox]::Show(
                    'Die Zwischenablage enthaelt keinen Text.',
                    'Carmaja AP7.7a',
                    [Windows.Forms.MessageBoxButtons]::OK,
                    [Windows.Forms.MessageBoxIcon]::Warning
                ) | Out-Null
                return
            }
            $normalizedClipboardValue = $clipboardValue.TrimEnd([char[]]"`r`n")
            $sender.Tag.Text = $normalizedClipboardValue
            $sender.Tag.Tag = $normalizedClipboardValue
            $sender.Tag.BackColor = [Drawing.Color]::Honeydew
            [Windows.Forms.Clipboard]::Clear()
        })
        $form.Controls.Add($paste)
    }

    $ok = [Windows.Forms.Button]::new()
    $ok.Text = 'Sicher bereitstellen'
    $ok.Location = [Drawing.Point]::new(350, 342)
    $ok.Size = [Drawing.Size]::new(145, 30)
    $ok.Add_Click({
        $values = @{}
        foreach ($definition in $definitions) {
            $textBox = $controls[$definition.Key]
            $textBox.BackColor = [Drawing.Color]::White
            $value = [string]$textBox.Tag
            if ([string]::IsNullOrWhiteSpace($value)) {
                $textBox.BackColor = [Drawing.Color]::MistyRose
                $textBox.Focus()
                [Windows.Forms.MessageBox]::Show(
                    ("Das Feld '{0}' darf nicht leer sein." -f $definition.Label),
                    'Carmaja AP7.7a',
                    [Windows.Forms.MessageBoxButtons]::OK,
                    [Windows.Forms.MessageBoxIcon]::Warning
                ) | Out-Null
                return
            }
            if ($value.Contains("`n") -or $value.Contains("`r")) {
                $textBox.BackColor = [Drawing.Color]::MistyRose
                $textBox.Focus()
                [Windows.Forms.MessageBox]::Show(
                    ("Das Feld '{0}' enthaelt einen unzulaessigen Zeilenumbruch." -f $definition.Label),
                    'Carmaja AP7.7a',
                    [Windows.Forms.MessageBoxButtons]::OK,
                    [Windows.Forms.MessageBoxIcon]::Warning
                ) | Out-Null
                return
            }
            $values[$definition.Key] = if ($definition.Password) { $value } else { $value.Trim() }
        }
        if ($values.SourceDatabase -eq $values.RestoreDatabase -or $values.SourceUser -eq $values.RestoreUser) {
            [Windows.Forms.MessageBox]::Show(
                'Source und Restore muessen unterschiedliche Datenbanken und Benutzer verwenden.',
                'Carmaja AP7.7a',
                [Windows.Forms.MessageBoxButtons]::OK,
                [Windows.Forms.MessageBoxIcon]::Warning
            ) | Out-Null
            return
        }
        $form.Tag = $values
        $form.DialogResult = [Windows.Forms.DialogResult]::OK
        $form.Close()
    })
    $form.AcceptButton = $ok
    $form.Controls.Add($ok)

    $cancel = [Windows.Forms.Button]::new()
    $cancel.Text = 'Abbrechen'
    $cancel.Location = [Drawing.Point]::new(505, 342)
    $cancel.Size = [Drawing.Size]::new(95, 30)
    $cancel.DialogResult = [Windows.Forms.DialogResult]::Cancel
    $form.CancelButton = $cancel
    $form.Controls.Add($cancel)

    $form.Add_Shown({
        $form.Activate()
        $controls.SourceHost.Focus()
    })

    $dialogResult = $form.ShowDialog()
    if ($dialogResult -ne [Windows.Forms.DialogResult]::OK) {
        throw 'Die sichere E2E-Konfiguration wurde abgebrochen.'
    }

    $values = $form.Tag
    if ($values -isnot [hashtable]) {
        throw 'Die sichere E2E-Konfiguration konnte nicht aus dem Formular gelesen werden.'
    }

    $result = @{
        Source = @{
            Dsn = "mysql:host=$($values.SourceHost);port=3306;dbname=$($values.SourceDatabase);charset=utf8mb4"
            Database = $values.SourceDatabase
            User = $values.SourceUser
            Password = $values.SourcePassword
        }
        Restore = @{
            Dsn = "mysql:host=$($values.RestoreHost);port=3306;dbname=$($values.RestoreDatabase);charset=utf8mb4"
            Database = $values.RestoreDatabase
            User = $values.RestoreUser
            Password = $values.RestorePassword
        }
    }
    $form.Dispose()
    return $result
}

$databaseConfiguration = Read-BackupConfiguration
$source = $databaseConfiguration.Source
$restore = $databaseConfiguration.Restore
if ($source.Database -eq $restore.Database -or $source.User -eq $restore.User) {
    throw 'Source und Restore müssen unterschiedliche Datenbanken und Benutzer verwenden.'
}

$agentRoot = Join-Path $env:LOCALAPPDATA 'Carmaja\BackupAgent'
New-Item -ItemType Directory -Path $agentRoot -Force | Out-Null
$identity = '*' + [System.Security.Principal.WindowsIdentity]::GetCurrent().User.Value
& icacls.exe $agentRoot '/inheritance:r' '/grant:r' ("{0}:(OI)(CI)F" -f $identity) | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Der lokale Agentordner konnte nicht geschützt werden.' }

$retryCache = Join-Path $agentRoot 'ap77-e2e-config.dpapi'
$retryCacheTemporary = $retryCache + '.tmp-' + [guid]::NewGuid().ToString('N')
$keyBytes = New-Object byte[] 32
$rng = [Security.Cryptography.RandomNumberGenerator]::Create()
$temporary = Join-Path $agentRoot ('ap77-e2e-' + [guid]::NewGuid().ToString('N') + '.php')
$batch = Join-Path $agentRoot ('ap77-e2e-' + [guid]::NewGuid().ToString('N') + '.sftp')
$remoteUploaded = $false
$remoteSecured = $false
try {
    $rng.GetBytes($keyBytes)
    $key = [Convert]::ToBase64String($keyBytes)
    $payload = @"
<?php

declare(strict_types=1);

return [
    'environment' => 'test',
    'publishTarget' => 'test',
    'backupTestMode' => true,
    'productionPublishEnabled' => false,
    'githubAdapterEnabled' => false,
    'privateDir' => '/home/www/carmaja-private-test/ap77-backup-e2e-data',
    'productPrivateDir' => '/home/www/carmaja-private-test/ap77-backup-e2e-data/product-source',
    'commerceDsn' => $(Quote-Php $source.Dsn),
    'commerceUser' => $(Quote-Php $source.User),
    'commercePassword' => $(Quote-Php $source.Password),
    'commerceTlsCaPath' => null,
    'commerceRequireTls' => true,
    'commerceRestoreDsn' => $(Quote-Php $restore.Dsn),
    'commerceRestoreUser' => $(Quote-Php $restore.User),
    'commerceRestorePassword' => $(Quote-Php $restore.Password),
    'commerceRestoreTlsCaPath' => null,
    'commerceRestoreRequireTls' => true,
    'backupDirectory' => '/home/www/carmaja-private-test/ap77-backup-e2e-data/backups',
    'backupOffsiteTarget' => 'test-sink://ap7-backup-e2e',
    'backupEncryptionKey' => $(Quote-Php $key),
    'backupEncryptionKeyId' => 'carmaja-backup-e2e-v1',
];
"@
    $payloadBytes = [Text.Encoding]::UTF8.GetBytes($payload)
    $protectedBytes = [System.Security.Cryptography.ProtectedData]::Protect(
        $payloadBytes,
        $null,
        [System.Security.Cryptography.DataProtectionScope]::CurrentUser
    )
    [IO.File]::WriteAllBytes($retryCacheTemporary, $protectedBytes)
    Move-Item -LiteralPath $retryCacheTemporary -Destination $retryCache -Force
    [Array]::Clear($payloadBytes, 0, $payloadBytes.Length)
    [Array]::Clear($protectedBytes, 0, $protectedBytes.Length)
    [IO.File]::WriteAllText($temporary, $payload, [Text.UTF8Encoding]::new($false))
    $probe = & ssh.exe '-o' 'BatchMode=yes' '-o' 'StrictHostKeyChecking=yes' $SshAlias test '!' -e ('/home/www/' + $RemoteConfig) 2>&1
    if ($LASTEXITCODE -ne 0) { throw 'Die private E2E-Konfiguration existiert bereits; sie wird nicht überschrieben.' }
    [IO.File]::WriteAllText($batch, 'put "' + $temporary.Replace('\', '/') + '" "' + $RemoteConfig + '"' + "`n", [Text.UTF8Encoding]::new($false))
    & sftp.exe '-o' 'BatchMode=yes' '-o' 'StrictHostKeyChecking=yes' '-b' $batch $SshAlias | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'Die private E2E-Konfiguration konnte nicht übertragen werden.' }
    $remoteUploaded = $true
    & ssh.exe '-o' 'BatchMode=yes' '-o' 'StrictHostKeyChecking=yes' $SshAlias chmod 600 ('/home/www/' + $RemoteConfig) | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'Der Modus 0600 konnte nicht gesetzt werden.' }
    $remoteAbsolute = '/home/www/' + $RemoteConfig
    $verifyCommand = 'test -f "{0}"; test "$(stat -c %a "{0}")" = 600' -f $remoteAbsolute
    & ssh.exe '-o' 'BatchMode=yes' '-o' 'StrictHostKeyChecking=yes' $SshAlias $verifyCommand | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'Die private E2E-Konfiguration wurde nicht mit Modus 0600 bestätigt.' }
    $remoteSecured = $true
    [Windows.Forms.MessageBox]::Show(
        'Konfiguration erfolgreich angelegt und mit Modus 0600 bestaetigt.',
        'Carmaja AP7.7a',
        [Windows.Forms.MessageBoxButtons]::OK,
        [Windows.Forms.MessageBoxIcon]::Information
    ) | Out-Null
    Write-Output 'AP7.7a-E2E-Konfiguration sicher bereitgestellt.'
}
catch {
    [Windows.Forms.MessageBox]::Show(
        ('Bereitstellung fehlgeschlagen: ' + $_.Exception.Message),
        'Carmaja AP7.7a',
        [Windows.Forms.MessageBoxButtons]::OK,
        [Windows.Forms.MessageBoxIcon]::Error
    ) | Out-Null
    throw
}
finally {
    $rng.Dispose()
    [Array]::Clear($keyBytes, 0, $keyBytes.Length)
    Remove-Item -LiteralPath $temporary -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $batch -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $retryCacheTemporary -Force -ErrorAction SilentlyContinue
    if ($remoteUploaded -and -not $remoteSecured) {
        & ssh.exe '-o' 'BatchMode=yes' '-o' 'StrictHostKeyChecking=yes' $SshAlias rm -f ('/home/www/' + $RemoteConfig) | Out-Null
    }
    Remove-Variable key, payload, payloadBytes, protectedBytes, source, restore -ErrorAction SilentlyContinue
}
