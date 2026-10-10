import { beforeEach, describe, expect, it } from "vitest";

import { applyBranding, luminance, readableOn } from "@/lib/auth/branding";

import { tenantSettings } from "../../fixtures";

beforeEach(() => {
  document.documentElement.removeAttribute("style");
  document.head.innerHTML = "";
  document.title = "";
});

describe("applyBranding", () => {
  it("01 §7 sets the tenant colours as theme variables with readable text colours", () => {
    applyBranding(tenantSettings());

    const style = document.documentElement.style;
    expect(style.getPropertyValue("--brand-primary")).toBe("#0A5C36");
    expect(style.getPropertyValue("--brand-primary-foreground")).toBe("#ffffff");
    expect(style.getPropertyValue("--brand-secondary")).toBe("#FFFFFF");
    expect(style.getPropertyValue("--brand-secondary-foreground")).toBe("#111111");
  });

  it("keeps the default theme for a colour that is not hex", () => {
    applyBranding(tenantSettings({ colors: { primary: "red;}", secondary: "#fff" } }));

    expect(document.documentElement.style.getPropertyValue("--brand-primary")).toBe("");
  });

  it("sets the page title to the store name", () => {
    applyBranding(tenantSettings());

    expect(document.title).toBe("مكتبة النور");
  });

  it("adds the favicon link when the store has one", () => {
    applyBranding(tenantSettings({ favicon_url: "http://alnoor.gomal.test/storage/favicon.png" }));

    expect(document.querySelector<HTMLLinkElement>('link[rel="icon"]')?.href).toBe(
      "http://alnoor.gomal.test/storage/favicon.png",
    );
  });

  it("replaces an existing favicon instead of adding a second one", () => {
    document.head.innerHTML = '<link rel="icon" href="/favicon.ico">';

    applyBranding(tenantSettings({ favicon_url: "http://alnoor.gomal.test/storage/f.png" }));

    expect(document.querySelectorAll('link[rel="icon"]')).toHaveLength(1);
  });

  it("leaves the default favicon when the store has none", () => {
    document.head.innerHTML = '<link rel="icon" href="/favicon.ico">';

    applyBranding(tenantSettings());

    expect(document.querySelector<HTMLLinkElement>('link[rel="icon"]')?.getAttribute("href")).toBe("/favicon.ico");
  });
});

describe("luminance and readableOn", () => {
  it.each([
    ["#000", 0],
    ["#ffffff", 1],
    ["fff", 1],
  ])("computes the luminance of %s", (hex, expected) => {
    expect(luminance(hex)).toBeCloseTo(expected);
  });

  it("returns null for a non-hex value", () => {
    expect(luminance("rgb(0,0,0)")).toBeNull();
  });

  it.each([
    ["#FFD54F", "#111111"],
    ["#1B3A8C", "#ffffff"],
  ])("picks readable text on %s", (hex, text) => {
    expect(readableOn(hex)).toBe(text);
  });
});
