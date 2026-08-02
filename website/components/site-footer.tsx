import Link from "next/link";

import { InstagramLink } from "@/components/instagram-link";
import { siteContent } from "@/content/site-content";

export function SiteFooter() {
  return (
    <footer className="v2-footer">
      <div className="content-shell v2-footer-inner">
        <div>
          <p className="v2-footer-brand">{siteContent.brandName}</p>
          <p className="v2-footer-tagline">{siteContent.footer.tagline}</p>
        </div>

        <nav aria-label="Rechtliche Hinweise">
          <ul className="v2-footer-links">
            <li>
              <InstagramLink position="footer" />
            </li>
            <li>
              <Link href="/impressum">Impressum</Link>
            </li>
            <li>
              <Link href="/datenschutz">Datenschutz</Link>
            </li>
            <li>
              <Link href="/shopbedingungen">Shopbedingungen</Link>
            </li>
            <li>
              <Link href="/widerruf">Widerruf</Link>
            </li>
            <li>
              <Link href="/versand-und-zahlung">Versand und Zahlung</Link>
            </li>
          </ul>
        </nav>
      </div>
    </footer>
  );
}
