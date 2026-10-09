[CmdletBinding()]
param([switch]$SelfTest,[switch]$ImportKnownBetaConfig)
$ErrorActionPreference='Stop'
Add-Type -AssemblyName System.Security

function Assert-CartBetaPasswords([string]$StorePassword,[string]$KeyPassword) {
    foreach($value in @($StorePassword,$KeyPassword)) {
        if ([string]::IsNullOrEmpty($value) -or $value.Length -gt 4096 -or $value -match '[\x00\r\n]') { throw 'INVALID_PASSWORD_INPUT' }
    }
}

function Protect-CartBetaDirectory([string]$Path) {
    $sid=[Security.Principal.WindowsIdentity]::GetCurrent().User
    $system=[Security.Principal.SecurityIdentifier]::new('S-1-5-18')
    $acl=[Security.AccessControl.DirectorySecurity]::new()
    $acl.SetOwner($sid); $acl.SetAccessRuleProtection($true,$false)
    foreach($principal in @($sid,$system)) {
        $acl.AddAccessRule([Security.AccessControl.FileSystemAccessRule]::new($principal,'FullControl','ContainerInherit,ObjectInherit','None','Allow'))
    }
    Set-Acl -LiteralPath $Path -AclObject $acl
    if (!(Get-Acl -LiteralPath $Path).AreAccessRulesProtected) { throw 'PRIVATE_ACL_FAILED' }
}

function Save-CartBetaSigning([string]$StorePassword,[string]$KeyPassword) {
    Assert-CartBetaPasswords $StorePassword $KeyPassword
    $keystore='G:\BS-Stein-Hart-shop-cart\.signing\carmaja-product-management-beta.jks'
    if (!(Test-Path -LiteralPath $keystore -PathType Leaf) -or ((Get-Item -LiteralPath $keystore).Attributes -band [IO.FileAttributes]::ReparsePoint)) { throw 'KEYSTORE_UNAVAILABLE' }
    $java='C:\Program Files\Eclipse Adoptium\jdk-17.0.20.8-hotspot\bin\java.exe'
    $helper=Join-Path $PSScriptRoot 'verify-cart-beta-keystore.java'
    $start=[Diagnostics.ProcessStartInfo]::new(); $start.FileName=$java
    $start.Arguments='"'+$helper+'"'; $start.UseShellExecute=$false; $start.CreateNoWindow=$true
    $start.RedirectStandardOutput=$true; $start.RedirectStandardError=$true
    $start.EnvironmentVariables['CARMAJA_BETA_KEYSTORE_PATH']=$keystore
    $start.EnvironmentVariables['CARMAJA_BETA_STORE_PASSWORD']=$StorePassword
    $start.EnvironmentVariables['CARMAJA_BETA_KEY_PASSWORD']=$KeyPassword
    $process=$null; $bytes=$null; $encrypted=$null; $created=$false; $target=$null
    try {
        $process=[Diagnostics.Process]::Start($start)
        $stdout=$process.StandardOutput.ReadToEndAsync(); $stderr=$process.StandardError.ReadToEndAsync()
        if (!$process.WaitForExit(30000)) { $process.Kill(); throw 'SIGNING_CHECK_TIMEOUT' }
        $result=$stdout.GetAwaiter().GetResult().Trim(); $null=$stderr.GetAwaiter().GetResult()
        if ($process.ExitCode -ne 0 -or $result -ne 'CART_BETA_SIGNING_VERIFIED') { throw 'SIGNING_CHECK_FAILED' }
        $root=Join-Path ([Environment]::GetFolderPath('LocalApplicationData')) 'Carmaja\CartBetaSigning'
        $parent=Split-Path -Parent $root
        if ((Test-Path -LiteralPath $parent) -and ((Get-Item -LiteralPath $parent).Attributes -band [IO.FileAttributes]::ReparsePoint)) { throw 'PRIVATE_PARENT_LINK_REJECTED' }
        $target=Join-Path $root 'signing.dpapi.json'
        if (Test-Path -LiteralPath $target) { throw 'SIGNING_INPUT_ALREADY_EXISTS' }
        if (Test-Path -LiteralPath $root) {
            if (((Get-Item -LiteralPath $root).Attributes -band [IO.FileAttributes]::ReparsePoint) -or @(Get-ChildItem -LiteralPath $root -Force).Count -ne 0) { throw 'PRIVATE_DIRECTORY_NOT_EMPTY' }
        } else { $null=New-Item -ItemType Directory -Path $root -Force }
        Protect-CartBetaDirectory $root
        $plain=@{version=1;keyStorePath=$keystore;storePassword=$StorePassword;keyPassword=$KeyPassword} | ConvertTo-Json -Compress
        $bytes=[Text.Encoding]::UTF8.GetBytes($plain)
        $encrypted=[Security.Cryptography.ProtectedData]::Protect($bytes,$null,[Security.Cryptography.DataProtectionScope]::CurrentUser)
        $wrapped=@{version=1;protection='DPAPI-CurrentUser';payload=[Convert]::ToBase64String($encrypted)} | ConvertTo-Json -Compress
        $stream=[IO.File]::Open($target,[IO.FileMode]::CreateNew,[IO.FileAccess]::Write,[IO.FileShare]::None)
        $created=$true
        try { $out=[Text.Encoding]::UTF8.GetBytes($wrapped); $stream.Write($out,0,$out.Length); $stream.Flush($true) } finally { $stream.Dispose() }
        $recovered=[Security.Cryptography.ProtectedData]::Unprotect($encrypted,$null,[Security.Cryptography.DataProtectionScope]::CurrentUser)
        try { if ([Text.Encoding]::UTF8.GetString($recovered) -cne $plain) { throw 'DPAPI_CHECK_FAILED' } } finally { [Array]::Clear($recovered,0,$recovered.Length) }
    } catch {
        if ($created -and $null -ne $target) { Remove-Item -LiteralPath $target -Force -ErrorAction SilentlyContinue }
        throw 'BETA_PRIVATE_SETUP_FAILED'
    } finally {
        if ($null -ne $process) { $process.Dispose() }
        foreach($key in @('CARMAJA_BETA_STORE_PASSWORD','CARMAJA_BETA_KEY_PASSWORD')) { $start.EnvironmentVariables.Remove($key) }
        if ($null -ne $bytes) { [Array]::Clear($bytes,0,$bytes.Length) }
        if ($null -ne $encrypted) { [Array]::Clear($encrypted,0,$encrypted.Length) }
        $plain=$null; $StorePassword=$null; $KeyPassword=$null
    }
}

if ($ImportKnownBetaConfig) {
    # Only the explicitly identified private AP6 Beta contract; no discovery.
    $known='G:\BS-Stein-Hart-ap6\.signing\beta-keystore.properties'
    $values=@{}
    try {
        if ($SelfTest -or !(Test-Path -LiteralPath $known -PathType Leaf) -or
            ((Get-Item -LiteralPath $known).Attributes -band [IO.FileAttributes]::ReparsePoint)) { throw 'KNOWN_BETA_CONFIG_UNAVAILABLE' }
        foreach($line in [IO.File]::ReadAllLines($known)) {
            if ($line.Trim() -eq '' -or $line.TrimStart().StartsWith('#') -or $line.TrimStart().StartsWith('!')) { continue }
            $match=[regex]::Match($line,'^\s*(storeFile|storePassword|keyPassword|keyAlias)\s*[=:]([^\r\n]*)$')
            if (!$match.Success -or $values.ContainsKey($match.Groups[1].Value)) { throw 'UNSUPPORTED_PRIVATE_FORMAT' }
            $value=$match.Groups[2].Value.TrimStart()
            if ($value.Contains('\') -or $value -match '[^\x20-\x7E]') { throw 'UNSUPPORTED_PRIVATE_FORMAT' }
            $values[$match.Groups[1].Value]=$value
        }
        if ($values.storeFile -cne 'carmaja-product-management-beta.jks' -or
            ($values.ContainsKey('keyAlias') -and $values.keyAlias -cne 'carmaja-product-management-beta')) { throw 'BETA_BINDING_MISMATCH' }
        Save-CartBetaSigning ([string]$values.storePassword) ([string]$values.keyPassword)
        Write-Output 'CART_KNOWN_BETA_SIGNING_VERIFIED'; exit 0
    } catch {
        Write-Output 'CART_KNOWN_BETA_SIGNING_FAILED'; exit 1
    } finally {
        $values.Clear(); $line=$null; $value=$null; $match=$null
    }
}

if ($SelfTest) {
    Assert-CartBetaPasswords ' leading and trailing ' 'synthetic-key'
    foreach($bad in @('',"test`nline","test`rline",("x"*4097))) {
        $rejected=$false; try { Assert-CartBetaPasswords $bad 'synthetic' } catch { $rejected=$true }
        if (!$rejected) { throw 'SELFTEST_FAILED' }
    }
    $bytes=[Text.Encoding]::UTF8.GetBytes('synthetic-private-input')
    $cipher=[Security.Cryptography.ProtectedData]::Protect($bytes,$null,[Security.Cryptography.DataProtectionScope]::CurrentUser)
    $plain=[Security.Cryptography.ProtectedData]::Unprotect($cipher,$null,[Security.Cryptography.DataProtectionScope]::CurrentUser)
    if ([Convert]::ToBase64String($plain) -ne [Convert]::ToBase64String($bytes)) { throw 'DPAPI_SELFTEST_FAILED' }
    foreach($array in @($bytes,$cipher,$plain)) { [Array]::Clear($array,0,$array.Length) }
    Write-Output 'PASS beta input and in-memory DPAPI guards; no key access, files or network.'; exit 0
}
if ([Threading.Thread]::CurrentThread.ApartmentState -ne 'STA') { throw 'STA_REQUIRED' }
Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing
$form=[Windows.Forms.Form]::new(); $form.Text='Carmaja - Warenkorb-Beta-Signierung'; $form.StartPosition='CenterScreen'; $form.TopMost=$true
$form.FormBorderStyle='FixedDialog'; $form.MaximizeBox=$false; $form.MinimizeBox=$false; $form.ClientSize=[Drawing.Size]::new(720,280)
$intro=[Windows.Forms.Label]::new(); $intro.Location=[Drawing.Point]::new(16,12); $intro.Size=[Drawing.Size]::new(686,52)
$intro.Text='Vorhandener Beta-Keystore ist kopiert. Passwoerter werden nur lokal geprueft und benutzergebunden verschluesselt ausserhalb des Repositorys gespeichert. Kein Upload, kein Produktionsschluessel.'; $form.Controls.Add($intro)
$boxes=@{}; $index=0
foreach($field in @(@('store','Keystore-Passwort'),@('key','Schluessel-Passwort'))) {
    $label=[Windows.Forms.Label]::new(); $label.Text=$field[1]; $label.Location=[Drawing.Point]::new(16,(78+40*$index)); $label.Size=[Drawing.Size]::new(180,24); $form.Controls.Add($label)
    $box=[Windows.Forms.TextBox]::new(); $box.Location=[Drawing.Point]::new(202,(74+40*$index)); $box.Size=[Drawing.Size]::new(390,26)
    $box.UseSystemPasswordChar=$true; $box.ShortcutsEnabled=$true; $box.MaxLength=4096; $form.Controls.Add($box); $boxes[$field[0]]=$box
    $paste=[Windows.Forms.Button]::new(); $paste.Text='Einfuegen'; $paste.Location=[Drawing.Point]::new(602,(72+40*$index)); $paste.Size=[Drawing.Size]::new(102,28); $paste.Tag=$box
    $paste.Add_Click({param($sender,$eventArgs) $sender.Tag.Text=[Windows.Forms.Clipboard]::GetText().TrimEnd([char[]]"`r`n"); [Windows.Forms.Clipboard]::Clear()}); $form.Controls.Add($paste); $index++
}
$same=[Windows.Forms.CheckBox]::new(); $same.Text='Beide Passwoerter sind gleich'; $same.Checked=$true; $same.Location=[Drawing.Point]::new(202,152); $same.Size=[Drawing.Size]::new(400,26)
$boxes.key.Enabled=$false; $same.Tag=$boxes.key; $same.Add_CheckedChanged({param($sender,$eventArgs) $sender.Tag.Enabled=!$sender.Checked}); $form.Controls.Add($same)
$status=[Windows.Forms.Label]::new(); $status.Text='Passwoerter niemals in Chat oder Befehlszeilen kopieren.'; $status.Location=[Drawing.Point]::new(16,186); $status.Size=[Drawing.Size]::new(686,42); $form.Controls.Add($status)
$save=[Windows.Forms.Button]::new(); $save.Text='Sicher bereitstellen'; $save.Location=[Drawing.Point]::new(422,236); $save.Size=[Drawing.Size]::new(170,30)
$cancel=[Windows.Forms.Button]::new(); $cancel.Text='Abbrechen'; $cancel.Location=[Drawing.Point]::new(602,236); $cancel.Size=[Drawing.Size]::new(102,30); $cancel.DialogResult='Cancel'
$form.Tag=@{Boxes=$boxes;Same=$same;Status=$status;Save=$save;Cancel=$cancel}
$save.Add_Click({
    param($sender,$eventArgs)
    $dialog=$sender.FindForm(); $state=$dialog.Tag; $store=[string]$state.Boxes.store.Text
    $key=if($state.Same.Checked){$store}else{[string]$state.Boxes.key.Text}
    $state.Save.Enabled=$false; $state.Cancel.Enabled=$false; $state.Status.Text='Beta-Zertifikat und Schluesselzugriff werden lokal geprueft ...'; $dialog.Refresh()
    try {
        Save-CartBetaSigning $store $key
        foreach($box in $state.Boxes.Values){$box.Clear()}; [Windows.Forms.Clipboard]::Clear()
        [Windows.Forms.MessageBox]::Show('Beta-Signierung geprueft und geschuetzt bereitgestellt. Noch kein Build gestartet.','Carmaja','OK','Information') | Out-Null
        $dialog.DialogResult='OK'; $dialog.Close()
    } catch {
        $state.Status.Text='Pruefung oder geschuetzte Bereitstellung fehlgeschlagen. Passwoerter pruefen; keine Werte in den Chat kopieren.'
        $state.Save.Enabled=$true; $state.Cancel.Enabled=$true
    } finally {$store=$null; $key=$null}
})
$form.Controls.Add($save); $form.Controls.Add($cancel); $form.AcceptButton=$save; $form.CancelButton=$cancel
try {$null=$form.ShowDialog()} finally {foreach($box in $boxes.Values){$box.Clear()}; [Windows.Forms.Clipboard]::Clear(); $form.Dispose()}
