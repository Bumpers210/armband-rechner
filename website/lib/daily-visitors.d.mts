export const dailyVisitorConsentVersion: string;
export const consentLifetimeSeconds: number;
export const consentCookie: string;
export const visitorCookie: string;
export type VisitorChoice = "allow" | "deny";
export type CookieEnvironment = { cookie: () => string; setCookie: (value: string) => void };
export function dailyVisitorChoice(cookie: string, now?: number): VisitorChoice | null;
export function hasDailyVisitorConsent(cookie: string, now?: number): boolean;
export function setDailyVisitorChoice(env: CookieEnvironment, choice: VisitorChoice, now?: number): boolean;
export function clearDailyVisitor(env: CookieEnvironment): void;
export function recordDailyVisitor(env: CookieEnvironment & {
  enabled: boolean;
  now?: () => number;
  locks?: Pick<LockManager, "request">;
  crypto?: Pick<Crypto, "getRandomValues">;
  fetch: typeof fetch;
}, path: string): Promise<void>;
