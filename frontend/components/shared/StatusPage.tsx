import type { ReactNode } from "react";

/** A centred full-page message: store unavailable, not found, errors. */
export function StatusPage({ title, body, action }: { title: string; body?: string; action?: ReactNode }) {
  return (
    <main className="flex min-h-dvh flex-col items-center justify-center gap-4 p-6 text-center">
      <h1 className="text-2xl font-bold">{title}</h1>
      {body !== undefined && <p className="text-muted-foreground max-w-sm">{body}</p>}
      {action}
    </main>
  );
}
