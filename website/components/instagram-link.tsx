import { siteContent } from "@/content/site-content";

type TrackedLinkPosition = "footer";

type InstagramLinkProps = {
  position: TrackedLinkPosition;
};

export function InstagramLink({ position }: InstagramLinkProps) {
  const href = `${siteContent.tracking.endpoint}?target=instagram&position=${position}`;

  return (
    <a
      className="v2-instagram-link"
      href={href}
      target="_blank"
      rel="noopener noreferrer"
      aria-label={`${siteContent.instagram.label} – öffnet in einem neuen Tab`}
    >
      <span className="v2-instagram-icon" aria-hidden="true" />
      {siteContent.instagram.label}
    </a>
  );
}
