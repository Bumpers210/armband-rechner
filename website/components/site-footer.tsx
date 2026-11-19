/* oxlint-disable nextjs/no-img-element -- Statically generated logo variants preserve the original appearance. */
import brandImage from "@/content/brand-image-sources.json";
import Link from "next/link";

import { InstagramLink } from "@/components/instagram-link";
import { siteContent } from "@/content/site-content";
import { CelestialOrnament } from "@/components/celestial-ornament";

export function SiteFooter() {
  return (
    <footer className="v2-footer">
      <div className="content-shell v2-footer-inner">
        <div className="v2-footer-branding">
          <CelestialOrnament />
          <Link
            className="v2-brand"
            href="/"
            aria-label={`${siteContent.brandName} – Startseite`}
          >
            <picture className="responsive-picture">
              <source type="image/webp" srcSet={brandImage.webpSrcSet} sizes="44px" />
              <img className="v2-brand-logo" src={brandImage.fallback}
                srcSet={brandImage.pngSrcSet} sizes="44px" alt=""
                width={128} height={128} loading="lazy" decoding="async" />
            </picture>
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
