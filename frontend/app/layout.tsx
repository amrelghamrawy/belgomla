import type { Metadata, Viewport } from "next";
import { IBM_Plex_Sans_Arabic } from "next/font/google";

import { TenantGate } from "@/components/shared/TenantGate";
import { ar } from "@/lib/copy/ar";

import { Providers } from "./providers";
import "./globals.css";

// D40: IBM Plex Sans Arabic, which also carries the Latin glyphs for codes, emails and numbers.
const plexArabic = IBM_Plex_Sans_Arabic({
  variable: "--font-plex-arabic",
  subsets: ["arabic", "latin"],
  weight: ["400", "500", "700"],
  display: "swap",
});

export const metadata: Metadata = {
  title: ar.appName,
  description: ar.appDescription,
};

export const viewport: Viewport = {
  width: "device-width",
  initialScale: 1,
};

export default function RootLayout({ children }: LayoutProps<"/">) {
  return (
    <html lang="ar" dir="rtl" className={`${plexArabic.variable} h-full antialiased`}>
      <body className="flex min-h-full flex-col">
        <Providers>
          <TenantGate>{children}</TenantGate>
        </Providers>
      </body>
    </html>
  );
}
