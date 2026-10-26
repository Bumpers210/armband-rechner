param([Parameter(Mandatory=$true)][string]$CredentialFile)
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing
Add-Type -AssemblyName System.Security
$privateBase = [IO.Path]::GetFullPath((Join-Path $env:LOCALAPPDATA 'Carmaja\V5DeviceTest'))
$privateFile = [IO.Path]::GetFullPath($CredentialFile)
if (-not $privateFile.StartsWith($privateBase + '\', [StringComparison]::OrdinalIgnoreCase) -or
    -not (Test-Path -LiteralPath $privateFile -PathType Leaf) -or
    ((Get-Item -LiteralPath $privateFile -Force).Attributes -band [IO.FileAttributes]::ReparsePoint)) {
    throw 'Keine sichere eigene V5-Testzugangsdatei.'
}
$clearBytes = [Security.Cryptography.ProtectedData]::Unprotect([IO.File]::ReadAllBytes($privateFile), $null,
    [Security.Cryptography.DataProtectionScope]::CurrentUser)
try { $login = [Text.Encoding]::UTF8.GetString($clearBytes) | ConvertFrom-Json }
finally { [Array]::Clear($clearBytes,0,$clearBytes.Length) }
if ($login.testOnly -ne $true -or $login.username -notmatch '^v5-handy-[0-9a-f]{10}$' -or
    $login.password -notmatch '^[0-9a-f]{24}$' -or $login.expiresEpoch -le [DateTimeOffset]::UtcNow.ToUnixTimeSeconds()) {
    throw 'Testzugang ist ungueltig oder abgelaufen.'
}
$form = New-Object Windows.Forms.Form
$form.Text = 'Carmaja - isolierter V5-Handytest: Testzugang'
$form.Size = New-Object Drawing.Size(700,290)
$form.StartPosition = 'CenterScreen'
$form.FormBorderStyle = 'FixedDialog'
$form.MaximizeBox = $false
$form.TopMost = $true
$info = New-Object Windows.Forms.Label
$info.Location = New-Object Drawing.Point(16,16)
$info.Size = New-Object Drawing.Size(660,55)
$info.Text = "Nur fuer Beta 1.4.0-beta.1 und test-api.carmaja-perlen.de.`r`nEigener Testzugang; bisherige Zugangsdateien und Produkte bleiben erhalten."
$form.Controls.Add($info)
$userLabel = New-Object Windows.Forms.Label
$userLabel.Location = New-Object Drawing.Point(16,80); $userLabel.Text = 'Benutzername'
$form.Controls.Add($userLabel)
$userBox = New-Object Windows.Forms.TextBox
$userBox.Location = New-Object Drawing.Point(130,76); $userBox.Size = New-Object Drawing.Size(360,24)
$userBox.ReadOnly = $true; $userBox.Text = $login.username; $form.Controls.Add($userBox)
$copyUser = New-Object Windows.Forms.Button
$copyUser.Location = New-Object Drawing.Point(510,74); $copyUser.Size = New-Object Drawing.Size(155,28)
$copyUser.Text = 'Benutzername kopieren'; $form.Controls.Add($copyUser)
$copyUser.Add_Click({ [Windows.Forms.Clipboard]::SetText($userBox.Text); $script:copiedPassword = $false })
$passwordLabel = New-Object Windows.Forms.Label
$passwordLabel.Location = New-Object Drawing.Point(16,120); $passwordLabel.Text = 'Testpasswort'
$form.Controls.Add($passwordLabel)
$passwordBox = New-Object Windows.Forms.TextBox
$passwordBox.Location = New-Object Drawing.Point(130,116); $passwordBox.Size = New-Object Drawing.Size(360,24)
$passwordBox.ReadOnly = $true; $passwordBox.UseSystemPasswordChar = $true; $passwordBox.Text = $login.password
$form.Controls.Add($passwordBox)
$copyPassword = New-Object Windows.Forms.Button
$copyPassword.Location = New-Object Drawing.Point(510,114); $copyPassword.Size = New-Object Drawing.Size(155,28)
$copyPassword.Text = 'Testpasswort kopieren'; $form.Controls.Add($copyPassword)
$copyPassword.Add_Click({ [Windows.Forms.Clipboard]::SetText($passwordBox.Text); $script:copiedPassword = $true })
$show = New-Object Windows.Forms.Button
$show.Location = New-Object Drawing.Point(130,156); $show.Size = New-Object Drawing.Size(180,28)
$show.Text = 'Passwort anzeigen/verbergen'; $form.Controls.Add($show)
$show.Add_Click({ $passwordBox.UseSystemPasswordChar = -not $passwordBox.UseSystemPasswordChar })
$expiry = New-Object Windows.Forms.Label
$expiry.Location = New-Object Drawing.Point(16,207); $expiry.Size = New-Object Drawing.Size(660,35)
$expiry.Text = 'Gueltig bis ' + [DateTimeOffset]::FromUnixTimeSeconds([long]$login.expiresEpoch).ToLocalTime().ToString('dd.MM.yyyy HH:mm') + '. Keine Zugangsdaten im Chat senden.'
$form.Controls.Add($expiry)
$form.Add_Shown({
    $form.Activate(); $form.BringToFront()
    [IO.File]::WriteAllText((Join-Path (Split-Path -Parent $privateFile) 'window-ready.txt'),
        [string]($form.Visible -and $form.WindowState -eq 'Normal'))
})
$form.Add_FormClosed({
    if ($script:copiedPassword -and [Windows.Forms.Clipboard]::ContainsText() -and
        [Windows.Forms.Clipboard]::GetText() -ceq $passwordBox.Text) { [Windows.Forms.Clipboard]::Clear() }
    $passwordBox.Text = ''; $login.password = ''; $form.Dispose()
})
[void]$form.ShowDialog()
