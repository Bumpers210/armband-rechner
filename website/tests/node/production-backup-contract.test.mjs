import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import path from "node:path";
import test from "node:test";

const root = path.dirname(process.cwd());
const read = (file) => readFile(path.join(root, file), "utf8");

test("Produktionsbackup ist privat, verschlüsselt und fail-closed", async () => {
  const deployment = JSON.parse(await read("website/config/production-shop-deployment.json"));
  const runtime = await read("website/config/runtime-config.production.example.php");
  const cli = await read("website/test-api-private/backup.php");
  const service = await read("website/test-api-private/program/production-backup.php");

  assert.equal(deployment.paths.backupCli, "/home/www/carmaja-private-shop/backup.php");
  assert.equal(deployment.runtime.backupCron, "17 * * * *");
  assert.equal(deployment.runtime.backupExecutionLimitSeconds, 40);
  assert.equal(deployment.runtime.backupServerRetentionDays, 7);
  assert.equal(deployment.runtime.backupOffsiteTarget, "onedrive-pull://carmaja-production/Carmaja-Perlen/Backups");
  assert.equal(deployment.guards.automaticApiDeployment, false);
  assert.equal(deployment.guards.automaticWebsiteDeployment, false);
  assert.equal(deployment.guards.publisherEnabled, false);
  assert.match(runtime, /'backupEncryptionKeyFile'\s*=>\s*'\/home\/www\/carmaja-private-shop\/config\/backup-key\.php'/);
  assert.match(runtime, /'backupEncryptionKey'\s*=>\s*null/);
  assert.doesNotMatch(runtime, /[A-Za-z0-9+/]{43}=/);
  assert.match(cli, /PHP_SAPI\s*!==\s*'cli'/);
  assert.match(cli, /'create'/);
  assert.match(cli, /'restore-dry-run'/);
  assert.match(service, /sodium_crypto_secretstream_xchacha20poly1305/);
  assert.match(service, /--single-transaction/);
  assert.match(service, /--events/);
  assert.match(service, /--no-tablespaces/);
  assert.match(service, /assertClientTls/);
  assert.doesNotMatch(service, /--set-gtid-purged=OFF/);
  assert.match(service, /backup_product_changed/);
  assert.match(service, /databaseFingerprint/);
  assert.match(service, /databaseFingerprint/);
  assert.match(service, /backup_database_changed/);
  assert.match(service, /cleanupStaging/);
  assert.doesNotMatch(service, /php:\/\/temp\/maxmemory/);
});

test("OneDrive-Pull prüft Pfad, Hash, freien Speicher und quittiert erst nach atomarer Ablage", async () => {
  const pull = await read("website/scripts/pull-production-backups.ps1");
  const install = await read("website/scripts/install-production-backup-task.ps1");
  const setup = await read("website/scripts/initialize-production-backup-key.ps1");

  assert.match(pull, /D:\\Carmaja-OneDrive\\OneDrive/);
  assert.match(pull, /D:\\Carmaja-OneDrive\\OneDrive\\Carmaja-OneDrive/);
  assert.match(install, /-BackupTargetRoot/);
  assert.match(pull, /Get-FileHash -Algorithm SHA256/);
  assert.match(pull, /10L \* \[Math\]::Max/);
  assert.match(pull, /5GB/);
  assert.match(pull, /Move-Item -LiteralPath \$stage -Destination \$target/);
  assert.match(pull, /Carmaja-Backup-Incoming/);
  assert.match(pull, /\.carmaja-backup-agent-owner/);
  assert.doesNotMatch(pull, /Join-Path \$targetRoot '\.incoming'/);
  assert.ok(pull.indexOf("Move-Item -LiteralPath $stage") < pull.indexOf("Confirm-Download $backup"));
  assert.match(pull, /BatchMode=yes/);
  assert.match(pull, /StrictHostKeyChecking=yes/);
  assert.match(pull, /Select-Object -First 48/);
  assert.match(pull, /AddDays\(-30\)/);
  assert.match(pull, /AddMonths\(-12\)/);
  assert.match(install, /FromMinutes\(30\)/);
  assert.match(install, /New-ScheduledTaskTrigger -AtLogOn/);
  assert.match(`${pull}\n${install}\n${setup}`, /'\*' \+ \[System\.Security\.Principal\.WindowsIdentity\]::GetCurrent\(\)\.User\.Value/);
  assert.match(setup, /RandomNumberGenerator/);
  assert.match(setup, /Set-Clipboard/);
  assert.match(setup, /Set-Clipboard -Value ' ' -ErrorAction Stop/);
  assert.doesNotMatch(setup, /Set-Clipboard -Value ''/);
  assert.match(setup, /Clipboard cleanup must never prevent removal of local key artifacts/);
  assert.match(setup, /GESICHERT/);
  assert.doesNotMatch(`${pull}\n${install}\n${setup}`, /backupEncryptionKey\s*=\s*['"][A-Za-z0-9+/]/);
});

test("IONOS-E2E bleibt auf künstliche Testziele begrenzt und bereinigt Zugangskonfiguration", async () => {
  const configure = await read("website/scripts/configure-backup-e2e.ps1");
  const e2e = await read("website/scripts/production-backup-e2e.php");
  const run = await read("website/scripts/run-backup-e2e.ps1");

  assert.match(configure, /carmaja-test-ionos/);
  assert.match(configure, /System\.Windows\.Forms/);
  assert.match(configure, /UseSystemPasswordChar/);
  assert.match(configure, /ProtectedData/);
  assert.match(configure, /DataProtectionScope]::CurrentUser/);
  assert.match(configure, /SourcePassword/);
  assert.doesNotMatch(configure, /Read-Host/);
  assert.match(configure, /carmaja-private-test\/ap77-backup-e2e-data/);
  assert.match(configure, /backupTestMode' => true/);
  assert.doesNotMatch(configure, /carmaja-private-shop|carmaja-production-ionos/);
  assert.match(e2e, /source_not_empty/);
  assert.match(e2e, /restore_not_empty/);
  assert.match(e2e, /backup_already_running/);
  assert.match(e2e, /crash_cleanup_failed/);
  assert.match(e2e, /ack_idempotency_failed/);
  assert.match(e2e, /restoreDryRun/);
  assert.match(e2e, /e2e_clear\(\$source\)/);
  assert.match(e2e, /e2e_remove_tree\(\$privateRoot\)/);
  assert.match(e2e, /@unlink\(\$runtimePath\)/);
  assert.match(run, /carmaja-test-ionos/);
  assert.match(run, /test ! -e \$remoteStage/);
  assert.match(run, /rm -rf \$remoteStage \$remoteData/);
  assert.match(run, /rm -f \$remoteConfig/);
  assert.match(run, /Restore-E2eConfigFromCache/);
  assert.doesNotMatch(`${configure}\n${e2e}\n${run}`, /api\.carmaja-perlen\.de|carmaja-private-production/);
});
