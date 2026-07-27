import { siteContent } from "@/content/site-content";

type ContactEmailLinkProps = {
  className: string;
};

export function ContactEmailLink({ className }: ContactEmailLinkProps) {
  return (
    <a className={className} href={`mailto:${siteContent.closing.email}`}>
      {siteContent.closing.email}
    </a>
  );
}
