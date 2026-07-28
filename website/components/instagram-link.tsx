import { siteTarget } from "@/config/site-target";
import { siteContent } from "@/content/site-content";

import type { TrackedLinkPosition } from "./vinted-link";

type InstagramLinkProps = {
  position: TrackedLinkPosition;
};

export function InstagramLink({ position }: InstagramLinkProps) {
  const href = siteTarget.isTest
    ? siteContent.instagram.url
    : `${siteContent.tracking.endpoint}?target=instagram&position=${position}`;

  return (
    <a
      href={href}
      target="_blank"
      rel="noopener noreferrer"
      aria-label={`${siteContent.instagram.label} – öffnet in einem neuen Tab`}
    >
      {siteContent.instagram.label}
    </a>
  );
}
