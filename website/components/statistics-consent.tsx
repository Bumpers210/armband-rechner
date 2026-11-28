"use client";

import { useEffect, useRef, useState } from "react";
import Link from "next/link";
import policy from "@/content/daily-visitors-policy.json";
import { dailyVisitorChoice, setDailyVisitorChoice, type VisitorChoice } from "@/lib/daily-visitors.mjs";

const enabled = process.env.NEXT_PUBLIC_DAILY_VISITORS_ENABLED === "true";
const changedEvent = "carmaja:analytics-consent";
const openEvent = "carmaja:analytics-settings";
const cookieEnvironment = () => ({ cookie: () => document.cookie, setCookie: (value: string) => { document.cookie = value; } });

export function StatisticsConsent() {
  const [open, setOpen] = useState(false);
  const [message, setMessage] = useState("");
  const heading = useRef<HTMLHeadingElement>(null);
  const focusRequested = useRef(false);
  const returnFocus = useRef<HTMLElement | null>(null);
  useEffect(() => {
    if (!enabled) return;
    const refresh = () => {
      setMessage("");
      try { setOpen(dailyVisitorChoice(document.cookie) === null); }
      catch { setOpen(true); }
    };
    const show = () => {
      setMessage("");
      returnFocus.current = document.activeElement as HTMLElement;
      focusRequested.current = true;
      setOpen(true);
      heading.current?.focus();
    };
    refresh();
    window.addEventListener(openEvent, show);
    window.addEventListener(changedEvent, refresh);
    window.addEventListener("focus", refresh);
    return () => {
      window.removeEventListener(openEvent, show);
      window.removeEventListener(changedEvent, refresh);
      window.removeEventListener("focus", refresh);
    };
  }, []);
  useEffect(() => {
    if (open && focusRequested.current) {
      heading.current?.focus();
      focusRequested.current = false;
    }
  }, [open]);
  if (!enabled) return null;
  const choose = (choice: VisitorChoice) => {
    let stored = false;
    try { stored = setDailyVisitorChoice(cookieEnvironment(), choice); } catch { /* fail closed */ }
    window.dispatchEvent(new Event(changedEvent));
    setMessage(stored ? (choice === "allow" ? "Statistik erlaubt. Du kannst jederzeit widerrufen." : "Statistik abgelehnt. Der Shop bleibt unverändert nutzbar.")
      : "Die Wahl konnte nicht gespeichert werden. Die Tagesbesucherzählung bleibt aus. Bitte prüfe deine Cookie-Einstellungen.");
    setOpen(!stored);
    if (stored) returnFocus.current?.focus();
  };
  return (
    <div className="statistics-consent-shell">
      {open && <section className="statistics-consent" aria-labelledby="statistics-consent-heading">
        <h2 id="statistics-consent-heading" ref={heading} tabIndex={-1}>{policy.title}</h2>
        {policy.status !== "approved" && <p className="statistics-consent-draft">{policy.status === "approved_pending_operations" ? "Lokale Testvorschau – Datenschutzfreigabe bestätigt, technische Aktivierung noch gesperrt." : "Lokale Testvorschau – Datenschutzfreigabe ausstehend."}</p>}
        <p>{policy.summary}</p>
        <p>{policy.choiceNotice}</p>
        <p><Link href="/datenschutz/">Details zur Tagesbesucherstatistik</Link> · <Link href="/impressum/">Impressum</Link></p>
        <div className="statistics-consent-actions">
          <button type="button" onClick={() => choose("allow")}>Statistik erlauben</button>
          <button type="button" onClick={() => choose("deny")}>Statistik ablehnen</button>
        </div>
      </section>}
      <p className="statistics-consent-result" role="status">{message}</p>
    </div>
  );
}

export function StatisticsSettings() {
  const [allowed, setAllowed] = useState(false);
  const [message, setMessage] = useState("");
  useEffect(() => {
    if (!enabled) return;
    const refresh = () => { setMessage(""); try { setAllowed(dailyVisitorChoice(document.cookie) === "allow"); } catch { setAllowed(false); } };
    refresh();
    window.addEventListener(changedEvent, refresh);
    window.addEventListener("focus", refresh);
    return () => { window.removeEventListener(changedEvent, refresh); window.removeEventListener("focus", refresh); };
  }, []);
  if (!enabled) return null;
  const withdraw = () => {
    let stored = false;
    try { stored = setDailyVisitorChoice(cookieEnvironment(), "deny"); } catch { /* no further count without consent */ }
    window.dispatchEvent(new Event(changedEvent));
    setMessage(stored ? "Statistik-Zustimmung widerrufen." : "Widerruf konnte nicht gespeichert werden. Bitte lösche die Website-Cookies in deinem Browser.");
  };
  return <li className="statistics-settings">
    <button type="button" onClick={() => window.dispatchEvent(new Event(openEvent))}>Statistik-Einstellungen</button>
    {allowed && <button type="button" onClick={withdraw}>Statistik-Zustimmung widerrufen</button>}
    <span role="status">{message}</span>
  </li>;
}
