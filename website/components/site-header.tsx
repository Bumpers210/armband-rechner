"use client";

import Link from "next/link";
import { useEffect, useRef, useState } from "react";

import { siteContent } from "@/content/site-content";

export function SiteHeader() {
  const [isMenuOpen, setIsMenuOpen] = useState(false);
  const menuButtonRef = useRef<HTMLButtonElement>(null);
  const [brandPrimary, brandSecondary] = siteContent.brandName.split("-");

  useEffect(() => {
    if (!isMenuOpen) {
      return;
    }

    function closeOnEscape(event: KeyboardEvent) {
      if (event.key === "Escape") {
        setIsMenuOpen(false);
        menuButtonRef.current?.focus();
      }
    }

    document.addEventListener("keydown", closeOnEscape);
    return () => document.removeEventListener("keydown", closeOnEscape);
  }, [isMenuOpen]);

  return (
    <header className="v2-header">
      <div className="content-shell v2-header-inner">
        <Link
          className="v2-brand"
          href="/"
          aria-label={`${siteContent.brandName} – Startseite`}
          onClick={() => setIsMenuOpen(false)}
        >
          <span className="v2-brand-primary">{brandPrimary}</span>
          {brandSecondary ? (
            <span className="v2-brand-secondary">
              {brandSecondary.toUpperCase()}
            </span>
          ) : null}
        </Link>

        <button
          className="v2-menu-toggle"
          type="button"
          aria-label={isMenuOpen ? "Menü schließen" : "Menü öffnen"}
          aria-expanded={isMenuOpen}
          aria-controls="site-navigation"
          onClick={() => setIsMenuOpen((isOpen) => !isOpen)}
          ref={menuButtonRef}
        >
          <span aria-hidden="true" />
          <span aria-hidden="true" />
          <span aria-hidden="true" />
        </button>

        <nav
          className="v2-navigation-panel"
          id="site-navigation"
          aria-label="Hauptnavigation"
          data-open={isMenuOpen}
        >
          <ul className="v2-navigation">
            {siteContent.navigation.map((item) => (
              <li key={item.href}>
                <Link
                  href={item.href}
                  onClick={() => setIsMenuOpen(false)}
                >
                  {item.label}
                </Link>
              </li>
            ))}
          </ul>
        </nav>
      </div>
    </header>
  );
}
