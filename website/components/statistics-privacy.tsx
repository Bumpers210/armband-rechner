import policy from "@/content/daily-visitors-policy.json";

export function StatisticsPrivacy() {
  if (process.env.NEXT_PUBLIC_DAILY_VISITORS_ENABLED !== "true" || policy.status === "approved") return null;
  const approvalConfirmed = policy.status === "approved_pending_operations";
  return <section id="tagesbesucher" className="statistics-privacy">
    <h2>Vorbereitung: Tagesbesucherstatistik</h2>
    <p>{approvalConfirmed ? "Datenschutzfreigabe bestätigt; technische Betriebsnachweise und Veröffentlichung stehen noch aus." : "Datenschutzfreigabe ausstehend."} Stand: {policy.date}. Nur lokale Testvorschau: Vor Aktivierung muss diese Fassung in eine neue versionierte Datenschutzfassung übernommen werden. Die bisherige Aussage „keine Analyse-Cookies“ gilt nicht für diese Erweiterung.</p>
    {policy.sections.map((section) => <section key={section.heading}>
      <h3>{section.heading}</h3>
      {section.paragraphs.map((paragraph) => <p key={paragraph}>{paragraph}</p>)}
    </section>)}
  </section>;
}
