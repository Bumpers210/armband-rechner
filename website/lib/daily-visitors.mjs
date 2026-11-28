export const dailyVisitorConsentVersion = "daily-visitors-v2";
export const consentLifetimeSeconds = 180 * 86400;
export const consentCookie = "carmaja_analytics_consent";
export const visitorCookie = "carmaja_daily_visitor";
const endpoint = "/daily-visitor.php";
const attributes = "; Path=/; Secure; SameSite=Lax"; // host-only, no shared-domain identifier
let pageBlocked = false;

function cookieValue(cookie, name) {
  return cookie.split(";").map((part) => part.trim()).find((part) => part.startsWith(name + "="))?.slice(name.length + 1) ?? "";
}

export function dailyVisitorChoice(cookie, now = Date.now()) {
  const match = /^daily-visitors-v2\.(allow|deny)\.(\d{10})$/u.exec(cookieValue(cookie, consentCookie));
  if (!match) return null;
  const age = Math.floor(now / 1000) - Number(match[2]);
  return age >= 0 && age < consentLifetimeSeconds ? match[1] : null;
}

export function hasDailyVisitorConsent(cookie, now = Date.now()) {
  return dailyVisitorChoice(cookie, now) === "allow";
}

export function clearDailyVisitor(env) {
  env.setCookie(visitorCookie + "=; Max-Age=0" + attributes);
}

// No random identity in the choice cookie; equal retention for allow and deny.
export function setDailyVisitorChoice(env, choice, now = Date.now()) {
  if (!["allow", "deny"].includes(choice)) throw new Error("Invalid consent choice");
  pageBlocked = true; // A failed cookie write must not keep this page collecting.
  if (choice === "deny") clearDailyVisitor(env);
  env.setCookie(consentCookie + "=" + dailyVisitorConsentVersion + "." + choice + "." + Math.floor(now / 1000)
    + "; Max-Age=" + consentLifetimeSeconds + attributes);
  const stored = cookieValue(env.cookie(), consentCookie) === dailyVisitorConsentVersion + "." + choice + "." + Math.floor(now / 1000);
  pageBlocked = choice === "deny" || !stored;
  return stored;
}

// Injectable environment: no visitor identity or network before consent.
export async function recordDailyVisitor(env, path) {
  const now = () => env.now?.() ?? Date.now();
  const consented = () => !pageBlocked && hasDailyVisitorConsent(env.cookie(), now());
  try {
    if (!env.enabled) return;
    if (!consented()) { clearDailyVisitor(env); return; }
    if (!env.locks?.request || !env.crypto?.getRandomValues || !/^\/(?:[a-z0-9-]+\/)*$/u.test(path)
        || /^\/(?:admin|artikelverwaltung|statistik)(?:\/|$)/u.test(path)) return;
    await env.locks.request(visitorCookie, async () => {
      if (!consented()) { clearDailyVisitor(env); return; }
      const response = await env.fetch(endpoint, { credentials: "same-origin", cache: "no-store" });
      if (response.status !== 200) return;
      const config = await response.json();
      if (!/^\d{4}-\d{2}-\d{2}$/u.test(config.day) || config.consentVersion !== dailyVisitorConsentVersion
          || !Number.isSafeInteger(config.expiresAt) || config.expiresAt * 1000 <= now()
          || config.expiresAt * 1000 - now() > 25 * 3600000) return;
      if (!consented()) { clearDailyVisitor(env); return; }
      const match = /^(\d{4}-\d{2}-\d{2})\.([a-f0-9]{64})\.([01])$/u.exec(cookieValue(env.cookie(), visitorCookie));
      const token = match?.[1] === config.day ? match[2]
        : Array.from(env.crypto.getRandomValues(new Uint8Array(32)), (v) => v.toString(16).padStart(2, "0")).join("");
      if (match?.[1] === config.day && match[3] === "1") return;
      const value = config.day + "." + token + ".0";
      const expiry = "; Expires=" + new Date(config.expiresAt * 1000).toUTCString() + attributes;
      env.setCookie(visitorCookie + "=" + value + expiry);
      if (cookieValue(env.cookie(), visitorCookie) !== value) return; // blocked cookies: fail closed
      if (!consented()) { clearDailyVisitor(env); return; }
      const result = await env.fetch(endpoint, {
        method: "POST", credentials: "same-origin", cache: "no-store", keepalive: true,
        body: new URLSearchParams({ day: config.day, token, path }),
      });
      if (!consented()) { clearDailyVisitor(env); return; }
      if (result.status === 204 && config.expiresAt * 1000 > now()) {
        env.setCookie(visitorCookie + "=" + config.day + "." + token + ".1" + expiry);
      }
    });
  } catch { /* Cookie/network failures never affect the shop. */ }
}
