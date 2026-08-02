import Link from "next/link";

import { siteContent } from "@/content/site-content";

type StoneKnowledgeBreadcrumbsProps = {
  current?: string;
};

export function StoneKnowledgeBreadcrumbs({
  current,
}: StoneKnowledgeBreadcrumbsProps) {
  const items = [
    { name: "Startseite", href: "/" },
    { name: "Steinwissen", href: "/steinwissen/" },
    ...(current ? [{ name: current }] : []),
  ];

  const jsonLdItems = items.map((item, index) => {
    if (item.href === undefined) {
      return {
        "@type": "ListItem",
        position: index + 1,
        name: item.name,
      };
    }

    return {
      "@type": "ListItem",
      position: index + 1,
      name: item.name,
      item: new URL(item.href, siteContent.metadata.siteUrl).toString(),
    };
  });

  const jsonLd = {
    "@context": "https://schema.org",
    "@type": "BreadcrumbList",
    itemListElement: jsonLdItems,
  };

  return (
    <>
      <nav className="stone-knowledge-breadcrumbs" aria-label="Breadcrumb">
        <ol>
          <li>
            <Link href="/">Startseite</Link>
          </li>
          <li aria-hidden="true">/</li>
          {current ? (
            <>
              <li>
                <Link href="/steinwissen/">Steinwissen</Link>
              </li>
              <li aria-hidden="true">/</li>
              <li aria-current="page">{current}</li>
            </>
          ) : (
            <li aria-current="page">Steinwissen</li>
          )}
        </ol>
      </nav>
      <script
        type="application/ld+json"
        dangerouslySetInnerHTML={{ __html: JSON.stringify(jsonLd) }}
      />
    </>
  );
}
