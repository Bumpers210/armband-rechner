/** Decorative moon phases: no extra text for assistive technology. */
export function CelestialOrnament() {
  return (
    <svg className="celestial-ornament" viewBox="0 0 240 44" fill="none" aria-hidden="true" focusable="false">
      <g stroke="currentColor" strokeWidth="1.2" strokeLinecap="round" strokeLinejoin="round">
        <path d="M1 22h35m168 0h35M48 17v10m-5-5h10M192 17v10m-5-5h10" />
        <path d="M84 9a13 13 0 1 0 0 26 16 16 0 0 1 0-26Z" />
        <circle cx="120" cy="22" r="13" />
        <path d="M156 9a13 13 0 1 1 0 26 16 16 0 0 0 0-26Z" />
        <path d="m120 2 2 3-2 3-2-3Zm0 34 2 3-2 3-2-3Z" />
      </g>
    </svg>
  );
}
