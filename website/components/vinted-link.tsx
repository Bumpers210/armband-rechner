/**
 * Retained as a compatibility module for the tracked-source manifest.
 * AP4 intentionally renders no external marketplace link.
 */
export type TrackedLinkPosition = "hero" | "gallery" | "contact" | "footer";

export function VintedLink(_props: { position: TrackedLinkPosition }) {
  void _props;
  return null;
}
