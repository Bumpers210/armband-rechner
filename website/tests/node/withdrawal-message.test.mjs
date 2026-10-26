import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { test } from "node:test";
import { withdrawalFailureMessage } from "../../lib/withdrawal-message.mjs";

test("Widerrufsfehler zeigen keine technischen oder unbekannten Serverdetails", () => {
  for (const code of [undefined, null, {}, "Failed to fetch", "Unexpected token <", "private-path-or-secret"]) {
    const preview = withdrawalFailureMessage("preview", code);
    const confirm = withdrawalFailureMessage("confirm", code);
    assert.match(preview, /noch kein Widerruf eingereicht/);
    assert.match(confirm, /konnte hier nicht bestätigt werden/);
    assert.match(confirm, /E-Mails.*Eingangsbestätigung/);
    assert.doesNotMatch(confirm, /noch kein Widerruf eingereicht|erneut bestätigen/);
    for (const text of [preview, confirm]) {
      assert.doesNotMatch(text, /Failed to fetch|Unexpected token|private-path-or-secret/);
      assert.match(text, /Kontaktdaten/);
    }
  }
});

test("Widerruf benennt bekannte Eingabe-, Sitzungs- und Rate-Limit-Fehler", () => {
  assert.match(withdrawalFailureMessage("preview", "withdrawal_identification_invalid"), /Bestellnummer, Name und E-Mail-Adresse/);
  assert.match(withdrawalFailureMessage("preview", "shop_rate_limited"), /warten Sie kurz/);
  for (const code of ["shop_session_required", "shop_session_expired", "csrf_invalid", "context_invalid"]) {
    assert.match(withdrawalFailureMessage("preview", code), /Sitzung ist abgelaufen/);
  }
  assert.match(withdrawalFailureMessage("confirm", "withdrawal_confirmation_invalid"), /konnte hier nicht bestätigt/);
});

test("Beide Formularschritte verwenden sichere Fehlermeldungen ohne automatische Wiederholung", async () => {
  const ui = await readFile(new URL("../../components/withdrawal-form.tsx", import.meta.url), "utf8");
  assert.doesNotMatch(ui, /error\.message|payload\.error\?\.message|setTimeout|setInterval/);
  for (const step of ["preview", "confirm"]) {
    assert.ok(ui.includes(`withdrawalFailureMessage("${step}", payload.error?.code)`));
    assert.ok(ui.includes(`withdrawalFailureMessage("${step}")`));
  }
  assert.match(ui, /state !== "submitted"/);
  assert.match(ui, /<p role="status">\{message\}<\/p>/);
  assert.match(ui, /Widerruf bestätigen/);
});
