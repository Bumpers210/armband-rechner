import { siteTarget } from "@/config/site-target";
import { siteContent } from "@/content/site-content";

export type TrackedLinkPosition =
  | "hero"
  | "gallery"
  | "contact"
  | "footer";

type VintedLinkProps = {
  position: TrackedLinkPosition;
};

export function VintedLink({ position }: VintedLinkProps) {
  const href = siteTarget.isTest
    ? siteContent.vinted.url
    : `${siteContent.tracking.endpoint}?target=vinted&position=${position}`;

  return (
    <a
      className="vinted-link"
      href={href}
      target="_blank"
      rel="noopener noreferrer"
      aria-label={`${siteContent.vinted.label} – öffnet in einem neuen Tab`}
    >
      {siteContent.vinted.label}
    </a>
  );
}
