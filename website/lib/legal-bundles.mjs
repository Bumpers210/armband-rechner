import { createHash } from "node:crypto";

export const LEGAL_BUNDLE_SCHEMA_VERSION = 1;

function canonicalize(value) {
  if (Array.isArray(value)) return value.map(canonicalize);
  if (value && typeof value === "object") {
    return Object.fromEntries(
      Object.keys(value)
        .sort()
        .map((key) => [key, canonicalize(value[key])]),
    );
  }
  return value;
}

function canonicalJson(value) {
  return JSON.stringify(canonicalize(value));
}

export function createLegalBundle(input) {
  const withoutHash = { ...input };
  const contentHash = createHash("sha256")
    .update(canonicalJson(withoutHash), "utf8")
    .digest("hex");
  return { ...withoutHash, contentHash };
}

export function validateLegalBundle(bundle, environment) {
  if (!bundle || bundle.environment !== environment) {
    throw new Error("Legal-Bundle-Umgebung stimmt nicht mit dem Buildziel überein.");
  }
  if (!/^cmj-(test|production)-legal-[0-9]{4}-[0-9]{2}-[0-9]{2}-v[0-9]+$/.test(bundle.id)) {
    throw new Error("Legal-Bundle-ID ist nicht kanonisch.");
  }
  if (!/^v[0-9]+$/.test(bundle.version)) {
    throw new Error("Legal-Bundle-Version ist ungültig.");
  }
  if (!/^sha256:[0-9a-f]{64}$/.test(`sha256:${bundle.contentHash}`)) {
    throw new Error("Legal-Bundle-Hash ist ungültig.");
  }
  const withoutHash = Object.fromEntries(
    Object.entries(bundle).filter(([key]) => key !== "contentHash"),
  );
  const expected = createLegalBundle(withoutHash);
  if (expected.contentHash !== bundle.contentHash) {
    throw new Error("Legal-Bundle-Hash stimmt nicht mit dem Inhalt überein.");
  }
  const expectedPrefix = environment === "test" ? "/legal-archive/test/" : "/legal-archive/production/";
  if (!bundle.archiveUrl.startsWith(expectedPrefix)) {
    throw new Error("Legal-Bundle-Archiv-URL gehört zur falschen Umgebung.");
  }
  if (environment === "production" && bundle.status === "test_only") {
    throw new Error("Testfassung darf nicht als Produktions-Bundle verwendet werden.");
  }
  if (bundle.status === "test_only" && environment !== "test") {
    throw new Error("Testfassung ist nur im Testziel zulässig.");
  }
}

export function legalBundleSnapshot(bundle) {
  return {
    legalBundleId: bundle.id,
    legalBundleHash: bundle.contentHash,
    legalBundleVersion: bundle.version,
    environment: bundle.environment,
  };
}

export function assertCheckoutLegalBundle(bundle, environment) {
  validateLegalBundle(bundle, environment);
  const allowed = environment === "test"
    ? bundle.status === "test_only"
    : bundle.status === "approved";
  if (!allowed) {
    throw new Error("Legal-Bundle ist für Checkout-Snapshots nicht freigegeben.");
  }
}

export function legalBundleHashInput(bundle) {
  const withoutHash = Object.fromEntries(
    Object.entries(bundle).filter(([key]) => key !== "contentHash"),
  );
  return canonicalJson(withoutHash);
}

export function assertLegalSnapshotMatchesBundle(snapshot, bundle, environment) {
  assertCheckoutLegalBundle(bundle, environment);
  const expected = legalBundleSnapshot(bundle);
  for (const key of Object.keys(expected)) {
    if (snapshot?.[key] !== expected[key]) {
      throw new Error(`Legal-Bundle-Snapshot stimmt bei ${key} nicht Ã¼berein.`);
    }
  }
}
