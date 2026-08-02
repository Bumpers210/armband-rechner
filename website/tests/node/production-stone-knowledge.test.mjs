import assert from "node:assert/strict";
import { access, readFile } from "node:fs/promises";
import path from "node:path";
import test from "node:test";

const websiteDirectory = process.cwd();

const routes = [
  {
    path: ["app", "steinwissen", "page.tsx"],
    canonical: "/steinwissen/",
    required: ["<h1>Steinwissen</h1>", "Spirituelle Bedeutung", "Natursteinkunde"],
  },
  {
    path: ["app", "steinwissen", "spirituelle-bedeutung", "page.tsx"],
    canonical: "/steinwissen/spirituelle-bedeutung/",
    required: ["wissenschaftlich nicht belegt", "keine medizinische Beratung oder Behandlung"],
  },
  {
    path: ["app", "steinwissen", "natursteinkunde", "page.tsx"],
    canonical: "/steinwissen/natursteinkunde/",
    required: [
      "Natursteinkunde",
      "Wie entstehen Natursteine?",
      "Warum sieht jeder Stein anders aus?",
      "Natürlich und behandelt",
      "StoneKnowledgeSourceList",
    ],
  },
];

async function text(...parts) {
  return readFile(path.join(websiteDirectory, ...parts), "utf8");
}

test("Steinwissen-Routen haben Inhalte, Canonicals und keine Profile", async () => {
  for (const route of routes) {
    const routePath = path.join(websiteDirectory, ...route.path);
    await access(routePath);
    const routeText = await readFile(routePath, "utf8");

    assert.ok(routeText.includes(`canonical: "${route.canonical}"`));
    assert.equal(routeText.includes("@/content/stone-knowledge\""), false);
    assert.equal(/(?:TODO|TBD|Platzhalter)/iu.test(routeText), false);
    for (const requiredText of route.required) {
      assert.ok(routeText.includes(requiredText));
    }
  }

  await assert.rejects(access(path.join(websiteDirectory, "app", "steinwissen", "[slug]")));
});

test("Navigation, Breadcrumbs und Sitemap führen zu den drei Steinwissen-Seiten", async () => {
  const [siteContent, header, breadcrumbs, sitemap] = await Promise.all([
    text("content", "site-content.ts"),
    text("components", "site-header.tsx"),
    text("components", "stone-knowledge-breadcrumbs.tsx"),
    text("app", "sitemap.ts"),
  ]);

  assert.ok(siteContent.includes('{ label: "Steinwissen", href: "/steinwissen/" }'));
  assert.ok(header.includes('item.href === "/steinwissen/"'));
  assert.ok(header.includes('pathname.startsWith("/steinwissen/")'));
  assert.ok(breadcrumbs.includes('aria-label="Breadcrumb"'));
  assert.ok(breadcrumbs.includes('"@type": "BreadcrumbList"'));

  for (const route of [
    "/steinwissen/",
    "/steinwissen/spirituelle-bedeutung/",
    "/steinwissen/natursteinkunde/",
  ]) {
    assert.ok(sitemap.includes(`"${route}"`));
  }
});

test("internes Modell hält Inventar, Quellen und Status getrennt von öffentlichem Inhalt", async () => {
  const [model, publicSources, editorialRules] = await Promise.all([
    text("content", "stone-knowledge.ts"),
    text("content", "stone-knowledge-public.ts"),
    text("docs", "stone-knowledge-editorial.md"),
  ]);

  for (const status of ["inventory", "draft", "approved", "published"]) {
    assert.ok(model.includes(`"${status}"`));
  }
  assert.equal((model.match(/status: "inventory"/g) ?? []).length, 16);
  assert.ok(model.includes("sources: readonly string[]"));
  assert.ok(model.includes('id: "app-active-materials"'));
  assert.ok(model.includes('id: "published-product-materials"'));
  assert.ok(publicSources.includes("https://www.gia.edu/gem-treatment"));
  assert.ok(publicSources.includes("https://www.mindat.org/a/about"));
  assert.ok(editorialRules.includes("Medizinische Wirkversprechen"));
  assert.ok(editorialRules.includes("Leere Profile"));
});

test("öffentliche Texte enthalten keine medizinischen Wirkversprechen oder Inventardaten", async () => {
  const publicText = (await Promise.all(routes.map((route) => text(...route.path)))).join("\n");

  assert.equal(
    /\b(?:heilt|lindert|entzündungshemmend|schmerzlindernd|therapiert)\b/iu.test(publicText),
    false,
  );
  for (const internalValue of ["stoneKnowledgeInventory", "Black Stone", "Rosenquarz"]) {
    assert.equal(publicText.includes(internalValue), false);
  }
});

test("Natursteinkunde erklärt Entstehung, Vielfalt und Bearbeitungen ohne ausgeschlossene Themen", async () => {
  const page = await text("app", "steinwissen", "natursteinkunde", "page.tsx");

  for (const requiredPattern of [
    /heißes, flüssiges Gestein\s+abkühlt/u,
    /Ablagerungen, die sich Schicht für Schicht\s+verdichten/u,
    /Gestein lange\s+unter Druck und Hitze steht/u,
    /Farbe, Maserung, Transparenz, Einschlüsse und Oberflächenstruktur/u,
    /kein Makel/u,
    /natürlichen Vielfalt und machen jeden Stein unverwechselbar/u,
    /erhitzt, gefärbt,\s+stabilisiert oder\s+beschichtet/u,
    /Polieren und Mattieren/u,
    /Synthetische Steine werden im Labor\s+hergestellt/u,
    /imitierte Steine ahmen nur das Aussehen eines\s+Natursteins nach/u,
  ]) {
    assert.match(page, requiredPattern);
  }

  assert.equal(
    /(?:Pflegehinweise|Härteskalen|chemische Formeln|Fundorte|spirituell)/iu.test(page),
    false,
  );
});

test("Natursteinkunde nutzt lokal gespeicherte, dokumentierte Bilder ohne Hotlinks", async () => {
  const [page, imageLicenses] = await Promise.all([
    text("app", "steinwissen", "natursteinkunde", "page.tsx"),
    text("docs", "stone-knowledge-image-licenses.md"),
  ]);

  const images = [
    {
      fileName: "natural-stones-texture.jpg",
      sourceUrl: "https://www.pexels.com/photo/close-up-of-colorful-stones-13328508/",
      photographer: "MGT Photos",
      alt: "Verschiedenfarbige natürliche Steine mit unterschiedlichen Oberflächen und Maserungen.",
    },
    {
      fileName: "polished-gemstones-colours.jpg",
      sourceUrl: "https://www.pexels.com/photo/a-pile-of-colorful-stones-and-rocks-19902306/",
      photographer: "Markus Winkler",
      alt: "Polierte Schmucksteine in verschiedenen Farben mit glänzenden Oberflächen und sichtbaren Mustern.",
    },
  ];

  assert.equal(page.includes("images.pexels.com"), false);
  for (const image of images) {
    const localPath = path.join(
      websiteDirectory,
      "public",
      "images",
      "stone-knowledge",
      image.fileName,
    );
    await access(localPath);
    const imageBytes = await readFile(localPath);
    assert.deepEqual([...imageBytes.subarray(0, 3)], [0xff, 0xd8, 0xff]);
    assert.ok(page.includes(`/images/stone-knowledge/${image.fileName}`));
    assert.ok(page.includes(image.alt));
    assert.ok(imageLicenses.includes(image.sourceUrl));
    assert.ok(imageLicenses.includes(image.photographer));
  }
  assert.ok(imageLicenses.includes("Pexels License"));
  assert.ok(imageLicenses.includes("Namensnennung: nicht erforderlich"));
});
