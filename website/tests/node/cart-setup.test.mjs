import assert from "node:assert/strict";
import {readFile} from "node:fs/promises";
import test from "node:test";

test("Eingabeskript liest eingefuegte Textwerte direkt und trennt Testziele", async () => {
  const script = await readFile(new URL("../../scripts/configure-cart-test.ps1", import.meta.url), "utf8");
  assert.match(script, /\$state\.Controls\[\$definition\.Key\]\.Text/);
  assert.match(script, /UseSystemPasswordChar/);
  assert.match(script, /dbs15962244/);
  assert.match(script, /dbs15962566/);
  assert.match(script, /StandardInput\.BaseStream\.Write/);
  assert.match(script, /StrictHostKeyChecking=yes/);
  assert.match(script, /PasswordAuthentication=no/);
  assert.doesNotMatch(script, /WriteAllText|WriteAllBytes|Set-Content|Out-File|StandardInputEncoding/);
});

test("Installer bleibt privat, atomar und ohne Datenbankmutation", async () => {
  const source = await readFile(new URL("../../scripts/install-cart-test-config.php", import.meta.url), "utf8");
  assert.match(source, /stream_get_contents\(STDIN/);
  assert.match(source, /\/home\/www\/carmaja-private-test/);
  assert.match(source, /chmod\(\$temporary, 0600\)/);
  assert.match(source, /@link\(\$temporary, \$destination\)/);
  assert.match(source, /CONFIG_EXISTS/);
  assert.doesNotMatch(source, /new PDO|mysqli_|SELECT |CREATE TABLE|runtime-config/);
});

test("MySQL-Praxisadapter prueft feste leere Ziele und vergibt keine Teilabnahme", async () => {
  const source = await readFile(new URL("../../scripts/cart-mysql-integration.php", import.meta.url), "utf8");
  assert.match(source, /test_identity_mismatch/);
  assert.match(source, /both_targets_must_be_empty/);
  assert.match(source, /mysql8_tls_required_client_missing/);
  assert.match(source, /private_mysql_client_directory_required/);
  assert.match(source, /NOT RUN backup\/restore: explicit functional-only run; no complete acceptance granted/);
  assert.match(source, /Discard failed transactions\/result sets/);
  assert.match(source, /unsafe_cleanup_identifier/);
  assert.doesNotMatch(source, /SOURCE_SCHEMA|RESTORE_SCHEMA|errorInfo\[2\]/);
});

test("Backup erzwingt TLS auch fuer CLI und liest nur seine privaten Optionen", async () => {
  const source = await readFile(new URL("../../scripts/commerce-backup.php", import.meta.url), "utf8");
  assert.match(source, /--defaults-file=/);
  assert.doesNotMatch(source, /--defaults-extra-file=/);
  assert.match(source, /ssl-mode=.*VERIFY_IDENTITY.*REQUIRED/);
  assert.match(source, /Ssl_cipher.*Ssl_version/);
  assert.match(source, /backup_cli_tls_inactive/);
  assert.match(source, /--no-tablespaces/);
  assert.match(source, /--set-gtid-purged=OFF/);
});

test("Crashnachweis bleibt isoliert und umfasst echte SIGKILL-/Timeout-/Wiederanlauffaelle", async () => {
  const source = await readFile(new URL("../../scripts/cart-mysql-faults.php", import.meta.url), "utf8");
  assert.match(source, /isolated_fault_stage_required/);
  assert.match(source, /fault_gate_expired/);
  assert.match(source, /proc_terminate\(\$job\['process'\], 9\)/);
  assert.match(source, /crash_finalization_partial_commit/);
  assert.match(source, /timeout_partial_commit/);
  assert.match(source, /deadlock_retry_not_exactly_once/);
  assert.match(source, /old_worker_released_new_lease/);
  assert.match(source, /calledOutsideTransaction/);
  assert.match(source, /fault_artifact_cleanup_failed/);
});

test("Stripe-Eingabe uebertraegt nur einen Testkey privat ohne lokale Credential-Datei", async () => {
  const script = await readFile(new URL("../../scripts/configure-cart-stripe-test.ps1", import.meta.url), "utf8");
  const installer = await readFile(new URL("../../scripts/install-cart-stripe-test.php", import.meta.url), "utf8");
  assert.match(script, /\$state\.Box\.Text/);
  assert.match(script, /UseSystemPasswordChar=\$true/);
  assert.match(script, /StandardInput\.BaseStream\.Write/);
  assert.match(script, /StrictHostKeyChecking=yes/);
  assert.doesNotMatch(script, /WriteAllText|WriteAllBytes|Set-Content|Out-File/);
  assert.match(installer, /sk_test_/);
  assert.match(installer, /shop-cart-stripe-test\.json/);
  assert.match(installer, /chmod\(\$temporary,0600\)/);
  assert.match(installer, /@link\(\$temporary,\$destination\)/);
  assert.doesNotMatch(installer, /new PDO|curl_|runtime-config|sk_live_/);
});

test("Brevo-Eingabe bleibt privat, explizit testbezogen und ohne Versand", async () => {
  const script = await readFile(new URL("../../scripts/configure-cart-brevo-test.ps1", import.meta.url), "utf8");
  const installer = await readFile(new URL("../../scripts/install-cart-brevo-test.php", import.meta.url), "utf8");
  assert.match(script, /\$state\.Boxes\[\$key\]\.Text/);
  assert.match(script, /UseSystemPasswordChar/);
  assert.match(script, /testRecipientConfirmed/);
  assert.match(script, /StandardInput\.BaseStream\.Write/);
  assert.match(script, /StrictHostKeyChecking=yes/);
  assert.doesNotMatch(script, /WriteAllText|WriteAllBytes|Set-Content|Out-File/);
  assert.match(installer, /shop-cart-brevo-test\.json/);
  assert.match(installer, /'testOnly' => true/);
  assert.match(installer, /@link\(\$temporary, \$destination\)/);
  assert.doesNotMatch(installer, /new PDO|curl_|runtime-config/);
});

test("Beta-Passwoerter bleiben maskiert und benutzergebunden ausserhalb von Git", async () => {
  const script = await readFile(new URL("../../scripts/configure-cart-beta-signing.ps1", import.meta.url), "utf8");
  const verifier = await readFile(new URL("../../scripts/verify-cart-beta-keystore.java", import.meta.url), "utf8");
  assert.match(script, /UseSystemPasswordChar=\$true/);
  assert.match(script, /DataProtectionScope\]::CurrentUser/);
  assert.match(script, /LocalApplicationData/);
  assert.match(script, /SetAccessRuleProtection\(\$true,\$false\)/);
  assert.match(script, /FileMode\]::CreateNew/);
  assert.match(script, /PRIVATE_PARENT_LINK_REJECTED/);
  assert.doesNotMatch(script, /ssh\.exe|sk_live_|production-keystore/);
  assert.match(script, /ImportKnownBetaConfig/);
  assert.match(script, /G:\\BS-Stein-Hart-ap6\\\.signing\\beta-keystore\.properties/);
  assert.match(script, /UNSUPPORTED_PRIVATE_FORMAT/);
  assert.match(script, /BETA_BINDING_MISMATCH/);
  assert.doesNotMatch(script, /Get-ChildItem.*Recurse|Write-Output.*values/);
  assert.doesNotMatch(script, /Arguments=.*Password/);
  assert.match(verifier, /carmaja-product-management-beta/);
  assert.match(verifier, /store\.getKey\(alias, keyPassword\)/);
  assert.match(verifier, /signer\.verify\(signed\)/);
  assert.match(verifier, /CART_BETA_SIGNING_INVALID/);
});
