import policy from "@/content/daily-visitors-policy.json";

export function StatisticsPrivacy() {
  if (process.env.NEXT_PUBLIC_DAILY_VISITORS_ENABLED !== "true" || policy.status === "approved") return null;
  return <section id="tagesbesucher" className="statistics-privacy">
    <h2>Entwurf: Tagesbesucherstatistik</h2>
    <p>Externe Datenschutzfreigabe ausstehend. Stand: {policy.date}. Nur lokale Testvorschau: Vor Aktivierung muss dieser Entwurf in eine neue freigegebene Datenschutzfassung übernommen werden. Die bisherige Aussage „keine Analyse-Cookies“ gilt nicht für diese vorgeschlagene Erweiterung.</p>
    {policy.sections.map((section) => <section key={section.heading}>
      <h3>{section.heading}</h3>
      {section.paragraphs.map((paragraph) => <p key={paragraph}>{paragraph}</p>)}
    </section>)}
  </section>;
}
