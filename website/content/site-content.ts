export type ImageOrientation = "landscape" | "portrait";
export type GalleryLayout = "wide" | "narrow" | "half";

export type SiteImage = {
  src: string;
  alt: string;
  width: number;
  height: number;
  orientation: ImageOrientation;
  objectPosition: string;
};

export type GalleryItem = {
  id: string;
  caption: string;
  layout: GalleryLayout;
  image: SiteImage;
};

export type LegalTextBlock =
  | {
      type: "paragraph";
      text: string;
    }
  | {
      type: "list";
      items: string[];
    }
  | {
      type: "address";
      lines: string[];
    }
  | {
      type: "email";
      before: string;
      address: string;
      after?: string;
    };

export type PrivacySection = {
  heading: string;
  blocks: LegalTextBlock[];
};

export type SiteContent = {
  brandName: string;
  metadata: {
    siteUrl: string;
    title: string;
    description: string;
  };
  navigation: Array<{
    label: string;
    href: string;
  }>;
  hero: {
    eyebrow: string;
    title: string;
    description: string;
    image: SiteImage;
  };
  introduction: string;
  instagram: {
    profileName: string;
    url: string;
    label: string;
  };
  tracking: {
    endpoint: string;
  };
  gallery: {
    eyebrow: string;
    title: string;
    introduction: string;
    items: GalleryItem[];
  };
  statement: string;
  maker: {
    eyebrow: string;
    title: string;
    text: string;
    image: SiteImage;
  };
  materials: {
    eyebrow: string;
    title: string;
    items: string[];
  };
  care: {
    title: string;
    items: string[];
  };
  closing: {
    eyebrow: string;
    title: string;
    customText: string;
    contactText: string;
    email: string;
  };
  legal: {
    imprint: {
      title: string;
      providerHeading: string;
      name: string;
      brandName: string;
      street: string;
      postalCodeAndCity: string;
      country: string;
      contactHeading: string;
      emailLabel: string;
      email: string;
      disputeHeading: string;
      disputeText: string;
    };
    privacy: {
      title: string;
      sections: PrivacySection[];
    };
  };
  footer: {
    tagline: string;
  };
};

export const siteContent: SiteContent = {
  brandName: "Carmaja-Perlen",
  metadata: {
    siteUrl: siteTarget.baseUrl,
    title: "Handgefertigte Edelsteinarmbänder | Carmaja-Perlen",
    description:
      "Handgefertigte Edelsteinarmbänder aus Rosenquarz, Amazonit, Achat und weiteren echten Edelsteinen – in kleinen Stückzahlen gefertigt von Carmaja-Perlen.",
  },
  navigation: [
    { label: "Armbänder", href: "/armbaender/" },
    { label: "Über mich", href: "/ueber-mich/" },
    { label: "Material & Pflege", href: "/material-pflege/" },
    { label: "Kontakt", href: "/kontakt/" },
  ],
  hero: {
    eyebrow: "Handgefertigt · Naturstein · kleine Stückzahlen",
    title: "Handgefertigte Perlenarmbänder aus echten Edelsteinen",
    description:
      "Liebevoll gefertigte Armbänder aus echten Edelsteinen. Ein hochwertiges Stück Natur, das schmückt und Bedeutung hat.",
    image: {
      src: "/images/bracelets/hero-dunkelrot-braun-holz.jpg",
      alt: "Dunkelrot-braunes Perlenarmband mit goldfarbenen Akzenten auf verwittertem Holz.",
      width: 2048,
      height: 1536,
      orientation: "landscape",
      objectPosition: "center 65%",
    },
  },
  introduction:
    "Jedes Armband entsteht in sorgfältiger Handarbeit. Farben, Maserungen und Einschlüsse machen die verwendeten Natursteine zu individuellen Begleitern.",
  instagram: {
    profileName: "carmaja_perlen",
    url: "https://www.instagram.com/carmaja_perlen/",
    label: "Instagram: @carmaja_perlen",
  },
  tracking: {
    endpoint: "/click.php",
  },
  gallery: {
    eyebrow: "Aus der Werkstatt",
    title: "Armbänder mit natürlichem Charakter",
    introduction:
      "Eine Auswahl handgefertigter Armbänder, fotografiert in natürlichem Licht auf Holz und Stein.",
    items: [
      {
        id: "grau-rosa",
        caption: "Netzstein und gefrosteter Rosenquarz",
        layout: "wide",
        image: {
          src: "/images/bracelets/galerie-grau-rosa-holz.jpg",
          alt: "Handgefertigtes Edelsteinarmband aus Netzstein und gefrostetem Rosenquarz.",
          width: 2048,
          height: 1536,
          orientation: "landscape",
          objectPosition: "center center",
        },
      },
      {
        id: "pink-rot",
        caption: "Roter Dragon-Veins-Achat und Erdbeerquarz",
        layout: "narrow",
        image: {
          src: "/images/bracelets/galerie-pink-rot-stein.jpg",
          alt: "Handgefertigtes Edelsteinarmband aus rotem Dragon-Veins-Achat und Erdbeerquarz.",
          width: 1536,
          height: 2048,
          orientation: "portrait",
          objectPosition: "center center",
        },
      },
      {
        id: "aqua-weiss",
        caption: "Amazonit und Lavastein",
        layout: "wide",
        image: {
          src: "/images/bracelets/galerie-aqua-weiss-holz.jpg",
          alt: "Handgefertigtes Edelsteinarmband aus Amazonit und Lavastein.",
          width: 2048,
          height: 1536,
          orientation: "landscape",
          objectPosition: "center 58%",
        },
      },
      {
        id: "dunkelrot-braun",
        caption:
          "Rotes Tigerauge und Lavastein mit roségoldfarbenen Akzenten",
        layout: "narrow",
        image: {
          src: "/images/bracelets/galerie-dunkelrot-braun-stein.jpg",
          alt: "Handgefertigtes Edelsteinarmband aus rotem Tigerauge und Lavastein mit roségoldfarbenen Akzenten.",
          width: 1536,
          height: 2048,
          orientation: "portrait",
          objectPosition: "center center",
        },
      },
      {
        id: "gruen-violett",
        caption: "Rubin-Zoisit",
        layout: "half",
        image: {
          src: "/images/bracelets/galerie-gruen-violett-holz.jpg",
          alt: "Handgefertigtes Edelsteinarmband aus Rubin-Zoisit.",
          width: 2048,
          height: 1536,
          orientation: "landscape",
          objectPosition: "center 55%",
        },
      },
      {
        id: "zartrosa",
        caption: "Gefrosteter Rosenquarz mit Edelstahl-Spacern",
        layout: "half",
        image: {
          src: "/images/bracelets/galerie-zartrosa-holz.jpg",
          alt: "Handgefertigtes Edelsteinarmband aus gefrostetem Rosenquarz mit Edelstahl-Spacern.",
          width: 2048,
          height: 1536,
          orientation: "landscape",
          objectPosition: "center 60%",
        },
      },
    ],
  },
  statement: "Jeder Stein ist anders. Jedes Armband auch.",
  maker: {
    eyebrow: "Persönlich gefertigt",
    title: "Eine Schatztruhe voller Steine",
    text:
      "Schon als Kind hatte ich eine Kiste voller Halbedelsteine, die ich spielerisch als meine „Schatztruhe“ bezeichnet habe. Die wunderschönen Farben und Muster der Steine haben mich schon damals fasziniert – und diese Faszination haben sie bis heute nicht verloren.",
    image: {
      src: "/images/bracelets/ueber-uns-armband-handgelenk.jpg",
      alt: "Dunkelrot-braunes Perlenarmband an einem Handgelenk im Freien.",
      width: 1536,
      height: 2048,
      orientation: "portrait",
      objectPosition: "center center",
    },
  },
  materials: {
    eyebrow: "Ausgewählt mit Gefühl",
    title: "Materialien",
    items: [
      "Natursteinperlen",
      "Quarzperlen",
      "Lavasteine",
      "Edelstahlelemente",
    ],
  },
  care: {
    title: "Pflege",
    items: [
      "Vor dem Duschen und Baden ablegen",
      "Kontakt mit Parfüm und Cremes vermeiden",
      "Nicht stark auseinanderziehen",
    ],
  },
  closing: {
    eyebrow: "Kontakt & aktuelle Armbänder",
    title: "Persönliche Anfragen",
    customText: "Individuelle Anfertigungen sind auf Anfrage möglich.",
    contactText:
      "Fragen zu Größen, Materialien oder individuellen Anfertigungen beantworten wir gerne per E-Mail.",
    email: "kontakt@carmaja-perlen.de",
  },
  legal: {
    imprint: {
      title: "Impressum",
      providerHeading: "Angaben gemäß § 5 DDG",
      name: "Carolin Buchner",
      brandName: "Carmaja-Perlen",
      street: "Bubenheim 170",
      postalCodeAndCity: "91757 Treuchtlingen",
      country: "Deutschland",
      contactHeading: "Kontakt",
      emailLabel: "E-Mail",
      email: "kontakt@carmaja-perlen.de",
      disputeHeading: "Verbraucherstreitbeilegung",
      disputeText:
        "Wir sind nicht bereit und nicht verpflichtet, an Streitbeilegungsverfahren vor einer Verbraucherschlichtungsstelle teilzunehmen.",
    },
    privacy: {
      title: "Datenschutzerklärung",
      sections: [
        {
          heading: "1. Verantwortliche Stelle",
          blocks: [
            {
              type: "paragraph",
              text: "Verantwortlich für die Datenverarbeitung auf dieser Website ist:",
            },
            {
              type: "address",
              lines: [
                "Carolin Buchner",
                "Carmaja-Perlen",
                "Bubenheim 170",
                "91757 Treuchtlingen",
                "Deutschland",
              ],
            },
            {
              type: "email",
              before: "E-Mail: ",
              address: "kontakt@carmaja-perlen.de",
            },
          ],
        },
        {
          heading: "2. Hosting",
          blocks: [
            {
              type: "paragraph",
              text: "Diese Website wird bei folgendem Anbieter gehostet:",
            },
            {
              type: "address",
              lines: [
                "IONOS SE",
                "Elgendorfer Straße 57",
                "56410 Montabaur",
                "Deutschland",
              ],
            },
            {
              type: "paragraph",
              text: "Beim Aufruf der Website verarbeitet der Hostinganbieter technisch erforderliche Zugriffsdaten. Dazu können insbesondere die angeforderte Seite oder Datei, die zuvor besuchte Seite, Browsertyp und Browserversion, Betriebssystem, Gerätetyp, Zugriffszeit sowie eine anonymisierte IP-Adresse gehören.",
            },
            {
              type: "paragraph",
              text: "Die Verarbeitung erfolgt zur sicheren, stabilen und fehlerfreien Bereitstellung der Website. Rechtsgrundlage ist Art. 6 Abs. 1 lit. f DSGVO. Unser berechtigtes Interesse liegt im sicheren und zuverlässigen Betrieb unseres Internetangebots.",
            },
            {
              type: "paragraph",
              text: "Die von IONOS im Rahmen des Webhostings verarbeiteten Besuchsdaten werden nach Angaben des Anbieters acht Wochen gespeichert. Ein Transfer dieser Besuchsdaten in Staaten außerhalb der Europäischen Union findet nach Angaben des Anbieters nicht statt.",
            },
          ],
        },
        {
          heading: "3. IONOS WebAnalytics",
          blocks: [
            {
              type: "paragraph",
              text: "Wir verwenden IONOS WebAnalytics zur statistischen Auswertung und technischen Optimierung unseres Internetangebots.",
            },
            {
              type: "paragraph",
              text: "Dabei können insbesondere folgende Informationen verarbeitet werden:",
            },
            {
              type: "list",
              items: [
                "zuvor besuchte Website",
                "angeforderte Seite oder Datei",
                "Browsertyp und Browserversion",
                "verwendetes Betriebssystem",
                "verwendeter Gerätetyp",
                "Zeitpunkt des Zugriffs",
                "anonymisierte IP-Adresse zur ungefähren Ortsbestimmung",
              ],
            },
            {
              type: "paragraph",
              text: "IONOS WebAnalytics verwendet nach Angaben des Anbieters keine Cookies. Die beim Seitenabruf übertragene IP-Adresse wird unmittelbar anonymisiert und anschließend ohne Personenbezug verarbeitet. Die Daten werden nicht an Dritte weitergegeben.",
            },
            {
              type: "paragraph",
              text: "Rechtsgrundlage ist Art. 6 Abs. 1 lit. f DSGVO. Unser berechtigtes Interesse liegt in der statistischen Auswertung, Verbesserung und technischen Optimierung der Website.",
            },
          ],
        },
        {
          heading: "4. Messung ausgehender Linkklicks",
          blocks: [
            {
              type: "paragraph",
              text: "Wir zählen in zusammengefasster Form, wie häufig Links zu unserem Instagram-Profil angeklickt werden.",
            },
            {
              type: "paragraph",
              text: "Dabei speichern wir ausschließlich:",
            },
            {
              type: "list",
              items: [
                "das Ziel des Links, beispielsweise Instagram",
                "die Position des Links auf der Website",
                "den Kalendertag des Klicks",
                "die zusammengefasste Anzahl der Klicks",
              ],
            },
            {
              type: "paragraph",
              text: "Wir speichern für diese Auswertung keine vollständigen IP-Adressen, Cookies, individuellen Besucherkennungen, Referrer-Adressen, Browserkennungen oder Nutzerprofile. Die Auswertung ermöglicht keine Identifizierung einzelner Besucher.",
            },
            {
              type: "paragraph",
              text: "Die Daten werden für höchstens zwölf Monate gespeichert und anschließend gelöscht oder dauerhaft zusammengefasst.",
            },
            {
              type: "paragraph",
              text: "Rechtsgrundlage ist Art. 6 Abs. 1 lit. f DSGVO. Unser berechtigtes Interesse liegt darin zu erkennen, welche externen Angebote für Besucher unserer Website relevant sind.",
            },
          ],
        },
        {
          heading: "5. Kontaktaufnahme per E-Mail",
          blocks: [
            {
              type: "paragraph",
              text: "Wenn Sie uns per E-Mail kontaktieren, verarbeiten wir die von Ihnen übermittelten Angaben. Dazu können insbesondere Ihre E-Mail-Adresse, Ihr Name, der Inhalt Ihrer Nachricht und weitere freiwillig übermittelte Informationen gehören.",
            },
            {
              type: "paragraph",
              text: "Wir verwenden diese Daten ausschließlich zur Bearbeitung und Beantwortung Ihrer Anfrage.",
            },
            {
              type: "paragraph",
              text: "Soweit Ihre Anfrage auf die Anbahnung eines Vertrags gerichtet ist, erfolgt die Verarbeitung auf Grundlage von Art. 6 Abs. 1 lit. b DSGVO. Bei allgemeinen Anfragen erfolgt die Verarbeitung auf Grundlage von Art. 6 Abs. 1 lit. f DSGVO. Unser berechtigtes Interesse liegt in der Bearbeitung eingehender Anfragen.",
            },
            {
              type: "paragraph",
              text: "Die Daten werden gelöscht, sobald die Anfrage abschließend bearbeitet wurde und keine gesetzlichen Aufbewahrungspflichten oder sonstigen berechtigten Gründe für eine weitere Speicherung bestehen.",
            },
            {
              type: "paragraph",
              text: "Bitte beachten Sie, dass eine unverschlüsselte E-Mail-Kommunikation Sicherheitsrisiken aufweisen kann. Übermitteln Sie per E-Mail keine besonders sensiblen Daten.",
            },
          ],
        },
        {
          heading: "6. Links zu externen Plattformen",
          blocks: [
            {
              type: "paragraph",
              text: "Unsere Website enthält Links zu Instagram.",
            },
            {
              type: "paragraph",
              text: "Beim bloßen Aufruf unserer Website wird aufgrund dieser normalen Links noch keine Verbindung zu den Plattformen hergestellt. Erst wenn Sie einen Link anklicken, verlassen Sie unsere Website. Für die anschließende Datenverarbeitung ist der jeweilige Plattformbetreiber verantwortlich.",
            },
            {
              type: "paragraph",
              text: "Vor der Weiterleitung kann der Klick entsprechend Abschnitt 4 in zusammengefasster Form gezählt werden.",
            },
            {
              type: "paragraph",
              text: "Auf unserer Website werden keine Beiträge, Bilder, Videos oder sonstigen Inhalte dieser Plattformen eingebettet.",
            },
          ],
        },
        {
          heading: "7. Empfänger personenbezogener Daten",
          blocks: [
            {
              type: "paragraph",
              text: "Personenbezogene Daten werden nur weitergegeben, wenn dies zur Bereitstellung der Website, zur Bearbeitung Ihrer Anfrage, zur Erfüllung gesetzlicher Verpflichtungen oder zur Wahrung berechtigter Interessen erforderlich ist.",
            },
            {
              type: "paragraph",
              text: "Im Rahmen des Hostings kann IONOS SE als Auftragsverarbeiter tätig werden.",
            },
          ],
        },
        {
          heading: "8. Ihre Rechte",
          blocks: [
            {
              type: "paragraph",
              text: "Sie haben im Rahmen der gesetzlichen Voraussetzungen insbesondere das Recht:",
            },
            {
              type: "list",
              items: [
                "Auskunft über Ihre verarbeiteten personenbezogenen Daten zu erhalten",
                "unrichtige Daten berichtigen zu lassen",
                "die Löschung Ihrer Daten zu verlangen",
                "die Verarbeitung Ihrer Daten einschränken zu lassen",
                "der Verarbeitung aufgrund berechtigter Interessen zu widersprechen",
                "Ihre Daten in einem übertragbaren Format zu erhalten, soweit die gesetzlichen Voraussetzungen vorliegen",
                "sich bei einer Datenschutzaufsichtsbehörde zu beschweren",
              ],
            },
            {
              type: "email",
              before: "Zur Ausübung Ihrer Rechte können Sie sich an ",
              address: "kontakt@carmaja-perlen.de",
              after: " wenden.",
            },
          ],
        },
        {
          heading: "9. Beschwerderecht",
          blocks: [
            {
              type: "paragraph",
              text: "Sie haben das Recht, sich bei einer Datenschutzaufsichtsbehörde zu beschweren.",
            },
            {
              type: "paragraph",
              text: "Für nicht öffentliche Stellen in Bayern ist unter anderem zuständig:",
            },
            {
              type: "address",
              lines: [
                "Bayerisches Landesamt für Datenschutzaufsicht",
                "Promenade 18",
                "91522 Ansbach",
                "Deutschland",
              ],
            },
          ],
        },
        {
          heading: "10. Aktualisierung dieser Datenschutzerklärung",
          blocks: [
            {
              type: "paragraph",
              text: "Wir passen diese Datenschutzerklärung an, wenn sich die Website, die eingesetzten Dienste oder die rechtlichen Anforderungen ändern.",
            },
            {
              type: "paragraph",
              text: "Stand: Juli 2026",
            },
          ],
        },
      ],
    },
  },
  footer: {
    tagline: "Handgefertigte Perlenarmbänder aus echten Edelsteinen",
  },
};
import { siteTarget } from "@/config/site-target";
