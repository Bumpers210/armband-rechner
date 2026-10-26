export function cartPaymentMessage(checkout) {
  const status = checkout?.payment_status;
  // A delayed payment stays authoritative even after the Checkout deadline.
  if (status === "processing") return "Ihre SEPA-Zahlung wird bearbeitet. Erst nach Zahlungserfolg bestätigen und versenden wir Ihre Bestellung.";
  if (status === "succeeded") return `Zahlung bestätigt. Bestellnummer: ${checkout.order_number ?? "wird geprüft"}.`;
  if (status === "manual_review") return "Ihre Zahlung wird geprüft. Bitte nicht erneut bestellen.";
  if (status === "failed") return "Zahlung fehlgeschlagen. Es wurde keine Bestellung bestätigt.";
  if (status === "canceled" || checkout?.state === "expired") return "Checkout ist beendet oder abgelaufen. Es wurde keine Bestellung bestätigt.";
  return "Zahlung noch nicht bestätigt. Bitte warten Sie auf die Bestellbestätigung.";
}
