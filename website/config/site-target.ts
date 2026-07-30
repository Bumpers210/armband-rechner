export type SiteTarget = "production" | "test";

const target = process.env.CARMAJA_SITE_TARGET;
const configuredUrl = process.env.CARMAJA_SITE_URL;

if (target !== "production" && target !== "test") {
  throw new Error("CARMAJA_SITE_TARGET muss production oder test sein.");
}

if (
  configuredUrl === undefined ||
  (target === "test" && configuredUrl !== "https://test.carmaja-perlen.de")
) {
  throw new Error(`CARMAJA_SITE_URL ist für ${target} ungültig.`);
}

export const siteTarget = {
  name: target,
  isTest: target === "test",
  baseUrl: `${configuredUrl}/`,
} as const;
