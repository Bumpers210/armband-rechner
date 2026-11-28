import assert from "node:assert/strict";
import test from "node:test";
import { readFileSync } from "node:fs";
import { assertDailyVisitorsPublication } from "../../lib/daily-visitors-publication.mjs";

const read = (path) => readFileSync(new URL(path, import.meta.url), "utf8");
const policy = JSON.parse(read("../../content/daily-visitors-policy.json"));
const proof = JSON.parse(read("../../docs/daily-visitors-cron-alarm-proof.template.json"));
const plan = read("../../docs/daily-visitors-cron-alarm-test-plan.md");

test("IONOS retention is 14 days for both copies and logs, separate from daily runtime cleanup", () => {
  assert.equal(policy.providerRetention.backupDays, 14);
  assert.equal(policy.providerRetention.logDays, 14);
  assert.equal(policy.providerRetention.automaticDeletion, true);
  assert.equal(policy.providerRetention.source, "operator_confirmation");
  assert.equal(policy.documentRevision, "daily-visitors-v2-ionos14d-2026-11-28");
  const text = policy.sections.flatMap((s) => s.paragraphs).join("\n");
  assert.match(text, /laufenden Statistiksystem.*00:15/u);
  assert.match(text, /Sicherungskopien und Serverlogs für 14 Tage/u);
  assert.match(text, /tägliche Bereinigung entfernt diese Kopien nicht/u);
  assert.match(text, /Eigene Statistikexporte enthalten ausschließlich kennungsfreie Tagesanzahlen/u);
  assert.doesNotMatch(text, /Prüfwerte dürfen nicht in Backups übernommen werden/u);
});

test("confirmed privacy approval does not enable production publication", () => {
  assert.equal(policy.approval.status, "confirmed_by_operator");
  assert.equal(policy.status, "approved_pending_operations");
  assert.equal(policy.version, "daily-visitors-v2");
  assert.throws(() => assertDailyVisitorsPublication({ enabled: true, target: "production", policy,
    bundle: { status: "approved", texts: { privacy: policy.sections } } }), /Datenschutzfassung/);
  for (const component of ["statistics-privacy.tsx", "statistics-consent.tsx"]) {
    const source = read(`../../components/${component}`);
    assert.match(source, /approved_pending_operations/);
    assert.match(source, /Datenschutzfreigabe bestätigt/u);
    assert.doesNotMatch(source, /externe Datenschutzfreigabe ausstehend/u);
  }
});

test("cron and alarm evidence is blank, isolated and cannot be mistaken for a real run", () => {
  assert.equal(proof.status, "not_run");
  assert.equal(proof.environment, "isolated_artificial_only");
  assert.equal(proof.activationAllowed, false);
  assert.equal(proof.codeCommit, null);
  assert.equal(proof.testRunId, null);
  assert.deepEqual(proof.cronRuns, []);
  for (const key of ["alarmSentAtUtc", "alarmReceivedAtUtc", "recoveryReceivedAtUtc"]) assert.equal(proof[key], null);
  assert.equal(proof.schedule.cleanup, "*/15 * * * *");
  assert.equal(proof.schedule.observerMinutes, 5);
  assert.equal(proof.schedule.maxRunSeconds, 40);
  assert.equal(proof.schedule.maxRetries, 1);
  assert.equal(proof.schedule.calendarTimezone, "Europe/Berlin");
  assert.equal(proof.schedule.logTimezone, "UTC");
  assert.equal(proof.providerRetention.backupDays, policy.providerRetention.backupDays);
  assert.equal(proof.providerRetention.logDays, policy.providerRetention.logDays);
});

test("every live proof has a planned criterion and requires real delivery and cleanup", () => {
  const required = ["preflight", "cron_runs", "midnight", "lock", "cleanup_outage", "alarm_delivery", "timeout", "recovery", "observer_outage", "provider_restore", "cleanup"];
  assert.deepEqual(proof.checks.map((item) => item.id), required);
  for (const check of proof.checks) {
    assert.equal(check.status, "not_run");
    assert.equal(check.evidenceRef, null);
    assert.ok(plan.includes(`| ${check.id} |`));
  }
  assert.match(plan, /900 Sekunden/);
  assert.match(plan, /tatsächlicher Cron/u);
  assert.match(plan, /echte Warnung/u);
  assert.match(plan, /unabhängige Ausfallkontrolle/u);
  assert.match(plan, /Keine Cronanlage/u);
  assert.match(plan, /alte.*Statistik|Statistiken bytegleich/u);
});
