"use client";

import { useEffect, useRef } from "react";
import { usePathname } from "next/navigation";
import { recordDailyVisitor } from "@/lib/daily-visitors.mjs";

type EntrySource =
  | "google"
  | "other-search"
  | "instagram"
  | "other-social"
  | "direct-unknown"
  | "other-website";

const pageViewEndpoint = "/pageview.php";

function hostMatches(hostname: string, domain: string) {
  return hostname === domain || hostname.endsWith(`.${domain}`);
}

function classifyEntrySource(referrer: string): EntrySource | undefined {
  if (!referrer) {
    return "direct-unknown";
  }

  try {
    const hostname = new URL(referrer).hostname.toLowerCase();

    if (hostname === window.location.hostname.toLowerCase()) {
      return undefined;
    }
    if (/(^|\.)google\.[a-z.]+$/u.test(hostname)) {
      return "google";
    }
    if (["bing.com", "duckduckgo.com", "ecosia.org", "search.yahoo.com", "startpage.com"].some((domain) => hostMatches(hostname, domain))) {
      return "other-search";
    }
    if (hostMatches(hostname, "instagram.com")) {
      return "instagram";
    }
    if (["facebook.com", "pinterest.com", "tiktok.com", "x.com", "t.co", "linkedin.com"].some((domain) => hostMatches(hostname, domain))) {
      return "other-social";
    }

    return "other-website";
  } catch {
    return "direct-unknown";
  }
}

function sendPageView(path: string, source?: EntrySource) {
  const payload = new URLSearchParams({ path });

  if (source) {
    payload.set("source", source);
  }

  const body = new Blob([payload.toString()], {
    type: "application/x-www-form-urlencoded;charset=UTF-8",
  });

  if (navigator.sendBeacon?.(pageViewEndpoint, body)) {
    return;
  }

  void fetch(pageViewEndpoint, {
    method: "POST",
    body: payload,
    keepalive: true,
    credentials: "same-origin",
  }).catch(() => undefined);
}

export function PageViewTracker() {
  const pathname = usePathname();
  const isInitialPage = useRef(true);

  useEffect(() => {
    if (!pathname) {
      return;
    }

    const source = isInitialPage.current
      ? classifyEntrySource(document.referrer)
      : undefined;

    isInitialPage.current = false;
    sendPageView(pathname, source);
    // Separate, default-off preparation. Never reuse shop or authentication identifiers.
    const countVisitor = () => {
      if (process.env.NEXT_PUBLIC_DAILY_VISITORS_ENABLED !== "true") return;
      try {
        void recordDailyVisitor({
          enabled: true, cookie: () => document.cookie, setCookie: (value) => { document.cookie = value; },
          locks: navigator.locks, crypto: window.crypto, fetch: window.fetch.bind(window),
        }, pathname.endsWith("/") ? pathname : `${pathname}/`);
      } catch { /* Storage can be unavailable even before the collector is called. */ }
    };
    countVisitor();
    const onVisible = () => { if (document.visibilityState === "visible") countVisitor(); };
    document.addEventListener("visibilitychange", onVisible);
    window.addEventListener("carmaja:analytics-consent", countVisitor);
    return () => {
      document.removeEventListener("visibilitychange", onVisible);
      window.removeEventListener("carmaja:analytics-consent", countVisitor);
    };
  }, [pathname]);

  return null;
}
