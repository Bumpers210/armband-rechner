import Image from "next/image";
import Link from "next/link";

import { InstagramLink } from "@/components/instagram-link";
import { siteContent } from "@/content/site-content";

export function SiteFooter() {
  return (
    <footer className="v2-footer">
      <div className="content-shell v2-footer-inner">
        <div className="v2-footer-branding">
          <Link
            className="v2-brand"
            href="/"
            aria-label={`${siteContent.brandName} – Startseite`}
          >
            <Image
              className="v2-brand-logo"
              src="/images/brand/carmaja-logo-transparent.png"
              alt=""
              width={128}
              height={128}
              sizes="44px"
            />
            <span className="v2-brand-wordmark" aria-hidden="true">
              <span className="v2-brand-primary">armaja</span>
              <span className="v2-brand-secondary">PERLEN</span>
            </span>
          </Link>
          <p className="v2-footer-tagline">{siteContent.footer.tagline}</p>
        </div>

        <nav aria-label="Rechtliche Hinweise">
          <ul className="v2-footer-links">
            <li>
              <InstagramLink position="footer" />
            </li>
            <li>
              <Link href="/impressum/">Impressum</Link>
            </li>
            <li>
              <Link href="/datenschutz/">Datenschutz</Link>
            </li>
            <li>
              <Link href="/shopbedingungen/">Shopbedingungen</Link>
            </li>
            <li>
              <Link href="/widerruf/">Vertrag widerrufen</Link>
            </li>
            <li>
              <Link href="/versand-und-zahlung/">Versand und Zahlung</Link>
            </li>
          </ul>
        </nav>
      </div>
    </footer>
  );
}
