import { siteTarget } from "@/config/site-target";
import deployment from "@/config/production-shop-deployment.json";
import { getActiveLegalBundle } from "@/content/legal-bundles";
import { siteContent } from "@/content/site-content";


function merchantReturnPolicy() {
  const bundle = getActiveLegalBundle();
  const paragraphs = bundle.texts.withdrawal.flatMap((section) => section.paragraphs);
  const withdrawal = paragraphs.find((paragraph) => paragraph.startsWith(
    "Sie haben das Recht, diesen Vertrag binnen vierzehn Tagen ohne Angabe von Gründen zu widerrufen. Die Frist beginnt an dem Tag, an dem Sie oder ein von Ihnen benannter Dritter, der nicht Beförderer ist, die Ware erhalten haben.",
  ));
  const dispatch = paragraphs.find((paragraph) => paragraph.startsWith(
    "Sie müssen die Ware spätestens binnen vierzehn Tagen nach Ihrem Widerruf an die oben genannte Anschrift absenden. Wir tragen die unmittelbaren Rücksendekosten innerhalb Deutschlands.",
  ));
  // Keep the numeric summary tied to the reviewed, published terms. A legal
  // change must be reviewed before a new build can reuse these return facts.
  if (bundle.status !== "approved" || !withdrawal || !dispatch) {
    throw new Error("Veröffentlichte Widerrufsbedingungen geändert: strukturierte Rückgabeangaben prüfen.");
  }
  return {
    "@type": "MerchantReturnPolicy",
    "@id": new URL("/#return-policy", siteContent.metadata.siteUrl).href,
    merchantReturnLink: new URL("/widerruf/", siteContent.metadata.siteUrl).href,
    applicableCountry: "DE",
    returnPolicyCountry: "DE",
    returnPolicyCategory: "https://schema.org/MerchantReturnFiniteReturnWindow",
    // The 14-day window is for initiating withdrawal after receipt, not for
    // receipt of the returned parcel. The separate dispatch deadline remains
    // explicit in the published description and linked full policy.
    merchantReturnDays: 14,
    returnMethod: "https://schema.org/ReturnByMail",
    returnFees: "https://schema.org/FreeReturn",
    description: [withdrawal, dispatch].join("\n\n"),
  };
}

export function merchantOfferPolicies() {
  const base = siteContent.metadata.siteUrl;
  return {
    seller: { "@id": new URL("/#organization", base).href },
    hasMerchantReturnPolicy: merchantReturnPolicy(),
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
    hasMerchantReturnPolicy: merchantReturnPolicy(),
  };
}
