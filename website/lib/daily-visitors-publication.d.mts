export function assertDailyVisitorsPublication(options: {
  enabled: boolean;
  target: string;
  policy: { status: string; sections: { heading: string; paragraphs: string[] }[] };
  bundle: { status: string; texts: { privacy: { heading: string; paragraphs: string[] }[] } };
}): void;
