export type LegalEnvironment = "test" | "production";
export type LegalBundleStatus =
  | "test_only"
  | "approved"
  | "awaiting_external_approval"
  | "retired";

export type LegalSection = {
  heading: string;
  paragraphs: string[];
  bullets?: string[];
};

export type LegalBundleTexts = {
  terms: LegalSection[];
  privacy: LegalSection[];
  withdrawal: LegalSection[];
  shipping: LegalSection[];
};

export type LegalBundle = {
  id: string;
  environment: LegalEnvironment;
  version: string;
  status: LegalBundleStatus;
  archiveUrl: string;
  createdAt: string;
  texts: LegalBundleTexts;
  contentHash: string;
};

export function createLegalBundle(input: Omit<LegalBundle, "contentHash">): LegalBundle;
export function validateLegalBundle(bundle: LegalBundle, environment: LegalEnvironment): void;
export function legalBundleSnapshot(bundle: LegalBundle): {
  legalBundleId: string;
  legalBundleHash: string;
  legalBundleVersion: string;
  environment: LegalEnvironment;
};
export function assertCheckoutLegalBundle(bundle: LegalBundle, environment: LegalEnvironment): void;
export function assertLegalSnapshotMatchesBundle(
  snapshot: Partial<ReturnType<typeof legalBundleSnapshot>>,
  bundle: LegalBundle,
  environment: LegalEnvironment,
): void;
