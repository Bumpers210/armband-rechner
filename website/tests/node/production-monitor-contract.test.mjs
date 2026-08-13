import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import path from "node:path";
import test from "node:test";

const repositoryRoot = path.dirname(process.cwd());
const read = (relativePath) => readFile(path.join(repositoryRoot, relativePath), "utf8");

test("Produktionsmonitor läuft privat im vorhandenen Worker-Takt und startet deaktiviert", async () => {
  const deployment = JSON.parse(await read("website/config/production-shop-deployment.json"));
  const runtime = await read("website/config/runtime-config.production.example.php");
  const worker = await read("website/test-api-private/worker.php");
  const bootstrap = await read("website/test-api-private/program/bootstrap.php");

  assert.equal(deployment.monitoring.runsWithWorker, true);
  assert.equal(deployment.monitoring.workerSuccessMaxAgeMinutes, 15);
  assert.equal(deployment.monitoring.queueBacklogMaxAgeMinutes, 15);
  assert.equal(deployment.monitoring.backupServerMaxAgeMinutes, 90);
  assert.equal(deployment.monitoring.backupOffsiteMaxAgeHours, 24);
  assert.equal(deployment.monitoring.storageWarningPercent, 90);
  assert.equal(deployment.monitoring.reminderHours, 6);
  assert.equal(deployment.paths.monitorState, "/home/www/carmaja-private-shop/monitor/state.json");
  assert.equal(deployment.paths.monitorCli, "/home/www/carmaja-private-shop/monitor.php");
  assert.equal(deployment.sources.privateMonitorCli, "website/test-api-private/monitor.php");
  assert.match(runtime, /'monitorEnabled'\s*=>\s*false/);
  assert.match(runtime, /'monitorAlertEmail'\s*=>\s*null/);
  assert.match(worker, /production-monitor\.php/);
  assert.match(worker, /productionMonitorSnapshot/);
  assert.match(worker, /new CarmajaProductionMonitor/);
  assert.match(bootstrap, /\$publishTarget !== 'production'/);
  assert.match(bootstrap, /FILTER_VALIDATE_EMAIL/);
});

test("Kontrollierte Testwarnung verlangt eine eindeutige Bestätigung", async () => {
  const cli = await read("website/test-api-private/monitor.php");

  assert.match(cli, /PHP_SAPI !== 'cli'/);
  assert.match(cli, /SEND-CARMAJA-PRODUCTION-MONITOR-TEST/);
  assert.match(cli, /sendTestAlert/);
  assert.doesNotMatch(cli, /getMessage\(\)/);
});

test("Monitor liest nur Betriebszustände und versendet keine Rohfehler oder Kundendaten", async () => {
  const monitor = await read("website/test-api-private/program/production-monitor.php");
  const repository = await read("website/test-api-private/program/commerce-core.php");

  assert.match(repository, /productionMonitorSnapshot/);
  assert.match(repository, /No row or global lock is acquired/);
  assert.match(monitor, /state\.json/);
  assert.match(monitor, /CarmajaProductionBackup::loadRuntime/);
  assert.match(monitor, /LOCK_EX/);
  assert.match(monitor, /rename\(\$temporary, \$this->stateFile\)/);
  assert.match(monitor, /Es wurden keine Kundendaten mitgesendet/);
  assert.doesNotMatch(monitor, /recipient.*payload|customer_email|customerEmail|order_number/);
  assert.doesNotMatch(monitor, /getMessage\(\)/);
});
