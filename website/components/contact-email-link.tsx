import type { ReactNode } from "react";

import { siteContent } from "@/content/site-content";

type ContactEmailLinkProps = {
  className: string;
  children?: ReactNode;
};

export function ContactEmailLink({
  className,
  children,
}: ContactEmailLinkProps) {
  return (
    <a className={className} href={`mailto:${siteContent.closing.email}`}>
      {children ?? siteContent.closing.email}
    </a>
  );
}
