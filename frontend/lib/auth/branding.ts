import type { TenantSettings } from "@/lib/api/types";

const HEX = /^#?([0-9a-f]{3}|[0-9a-f]{6})$/i;

/** Relative luminance (WCAG) of a hex colour, or null when it is not a hex colour. */
export function luminance(hex: string): number | null {
  const match = HEX.exec(hex.trim());

  if (match?.[1] === undefined) {
    return null;
  }

  const digits = match[1].length === 3 ? [...match[1]].map((d) => d + d).join("") : match[1];
  const [r = 0, g = 0, b = 0] = [0, 2, 4].map((i) => {
    const channel = parseInt(digits.slice(i, i + 2), 16) / 255;

    return channel <= 0.03928 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4;
  });

  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

/** Dark text on light brand colours, white text on dark ones. */
export function readableOn(hex: string): string {
  return (luminance(hex) ?? 0) > 0.45 ? "#111111" : "#ffffff";
}

/**
 * Whether the secondary colour can carry the storefront highlight (profit chips, the hero call to action). A
 * near-white secondary (a common "none" value) would vanish on the white cards, so the default amber stays.
 */
export function canHighlight(hex: string): boolean {
  const value = luminance(hex);

  return value !== null && value <= 0.8;
}

/** The text colour (#111111 or white) with the higher WCAG contrast on a background: mid-tone oranges get dark text. */
export function contrastText(hex: string): string {
  const background = (luminance(hex) ?? 0) + 0.05;

  return background / 0.0552 >= 1.05 / background ? "#111111" : "#ffffff";
}

/**
 * Applies the tenant's branding (01 §7): theme colour variables used by `bg-primary` etc., the storefront
 * highlight, the favicon and the page title. Colours that are not valid hex are ignored, so the default theme stays.
 */
export function applyBranding(settings: TenantSettings, doc: Document = document): void {
  const root = doc.documentElement.style;
  const { primary, secondary } = settings.colors;

  if (luminance(primary) !== null) {
    root.setProperty("--brand-primary", primary);
    root.setProperty("--brand-primary-foreground", readableOn(primary));
  }

  if (luminance(secondary) !== null) {
    root.setProperty("--brand-secondary", secondary);
    root.setProperty("--brand-secondary-foreground", readableOn(secondary));
  }

  if (canHighlight(secondary)) {
    root.setProperty("--brand-highlight", secondary);
    root.setProperty("--brand-highlight-foreground", contrastText(secondary));
  } else {
    root.removeProperty("--brand-highlight");
    root.removeProperty("--brand-highlight-foreground");
  }

  if (settings.favicon_url !== null) {
    let icon = doc.querySelector<HTMLLinkElement>('link[rel="icon"]');

    if (icon === null) {
      icon = doc.createElement("link");
      icon.rel = "icon";
      doc.head.appendChild(icon);
    }

    icon.href = settings.favicon_url;
  }

  doc.title = settings.name;
}
