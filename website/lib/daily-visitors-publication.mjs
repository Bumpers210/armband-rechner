export function assertDailyVisitorsPublication({ enabled, target, policy, bundle }) {
  if (!enabled || target !== "production") return;
  const sections = bundle?.texts?.privacy ?? [];
  const integrated = policy.sections.every((section) => sections.some((candidate) =>
    candidate.heading === section.heading && JSON.stringify(candidate.paragraphs) === JSON.stringify(section.paragraphs)));
  const obsoleteClaim = sections.some((section) => section.paragraphs?.some((paragraph) =>
    paragraph.includes("Eigene Analyse-, Werbe- oder Newsletter-Cookies setzen wir nicht ein.")));
  if (policy.status !== "approved" || bundle?.status !== "approved" || !integrated || obsoleteClaim) {
    throw new Error("Tagesbesucher: neue freigegebene Datenschutzfassung nicht vollständig eingebunden.");
  }
}
