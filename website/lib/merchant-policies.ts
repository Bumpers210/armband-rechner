import { siteTarget } from "@/config/site-target";
import deployment from "@/config/production-shop-deployment.json";
import { getActiveLegalBundle } from "@/content/legal-bundles";
import { siteContent } from "@/content/site-content";

export function merchantOfferPolicies() {
  const base = siteContent.metadata.siteUrl;
  return {
    seller: { "@id": new URL("/#organization", base).href },
    shippingDetails: {
      "@type": "OfferShippingDetails",
      hasShippingService: { "@id": new URL("/#shipping-service", base).href },
    },
  };
}

export type MerchantOfferPolicies = ReturnType<typeof merchantOfferPolicies>;

export function merchantOrganizationStructuredData() {
  const base = siteContent.metadata.siteUrl;
  const imprint = siteContent.legal.imprint;
  const [postalCode, ...localityParts] = imprint.postalCodeAndCity.split(" ");

  const organization = {
    "@context": "https://schema.org",
    "@type": "Organization",
    "@id": new URL("/#organization", base).href,
    name: siteContent.brandName,
    legalName: imprint.name,
    url: base,
    email: imprint.email,
    address: {
      "@type": "PostalAddress",
      streetAddress: imprint.street,
      postalCode,
      addressLocality: localityParts.join(" "),
      addressCountry: "DE",
    },
    sameAs: [siteContent.instagram.url],
  };
  if (siteTarget.isTest) return organization;

  const bundle = getActiveLegalBundle();
  const shipping = bundle.texts.shipping.find((section) => section.heading === "Versand");
  if (!shipping) throw new Error("Veröffentlichte Versandbedingungen fehlen.");

  return {
    ...organization,
    hasShippingService: {
      "@type": "ShippingService",
      "@id": new URL("/#shipping-service", base).href,
      name: deployment.shop.shippingPublicName,
      url: new URL("/versand-und-zahlung/", base).href,
      description: shipping.paragraphs.join("\n\n"),
      fulfillmentType: "https://schema.org/FulfillmentTypeDelivery",
      shippingConditions: {
        "@type": "ShippingConditions",
        shippingDestination: { "@type": "DefinedRegion", addressCountry: "DE" },
        shippingRate: {
          "@type": "MonetaryAmount",
          value: deployment.shop.shippingAmountMinor / 100,
          currency: "EUR",
        },
      },
    },
    // The published policy gives separate withdrawal and dispatch deadlines.
    // Google supports the complete policy URL without a simplified return window.
    hasMerchantReturnPolicy: {
      "@type": "MerchantReturnPolicy",
      merchantReturnLink: new URL("/widerruf/", base).href,
    },
  };
}
