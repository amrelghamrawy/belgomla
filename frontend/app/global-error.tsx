"use client";

import { ar } from "@/lib/copy/ar";

// Replaces the root layout when it fails, so it brings its own <html> and no global styles.
export default function GlobalError({ retry }: { error: Error & { digest?: string }; retry: () => void }) {
  return (
    <html lang="ar" dir="rtl">
      <body style={{ fontFamily: "system-ui, sans-serif", textAlign: "center", padding: "4rem 1.5rem" }}>
        <title>{ar.pages.errorTitle}</title>
        <h1>{ar.pages.errorTitle}</h1>
        <p>{ar.errors.server_error}</p>
        <button type="button" onClick={retry} style={{ minHeight: 44, padding: "0 1.5rem" }}>
          {ar.retry}
        </button>
      </body>
    </html>
  );
}
