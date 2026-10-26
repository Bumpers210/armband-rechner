// Only known error codes affect public copy. Never render provider/debug messages.
export function withdrawalFailureMessage(step, code) {
  if (code === "withdrawal_identification_invalid") {
    return "Bitte prüfen Sie Bestellnummer, Name und E-Mail-Adresse.";
  }
  if (code === "shop_rate_limited") {
    return "Es gab zu viele Anfragen. Bitte warten Sie kurz und versuchen Sie es erneut.";
  }
  if (["shop_session_required", "shop_session_expired", "csrf_invalid", "context_invalid"].includes(code)) {
    return "Ihre Sitzung ist abgelaufen. Bitte laden Sie die Seite neu und prüfen Sie Ihre Angaben erneut.";
  }
  if (step === "confirm") {
    // A lost response does not prove that the declaration was not stored.
    return "Der Eingang Ihres Widerrufs konnte hier nicht bestätigt werden. Bitte prüfen Sie Ihre E-Mails auf eine Eingangsbestätigung. Falls diese fehlt, können Sie uns über die Kontaktdaten in der Widerrufsbelehrung erreichen.";
  }
  return "Ihre Angaben konnten gerade nicht geprüft werden. Es wurde noch kein Widerruf eingereicht. Bitte versuchen Sie es erneut oder nutzen Sie die Kontaktdaten in der Widerrufsbelehrung.";
}
