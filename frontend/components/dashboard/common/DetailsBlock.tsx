import { ChevronDownIcon } from "lucide-react";
import type { ReactNode } from "react";

/**
 * D104: a long table folded under its chart or summary: the chart answers at a glance, the rows stay one click away
 * (and in the DOM, for screen readers and tests). Native <details>, so it works without script.
 */
export function DetailsBlock({ summary, children }: { summary: ReactNode; children: ReactNode }) {
  return (
    <details className="group">
      <summary className="text-primary hover:bg-primary-soft inline-flex cursor-pointer list-none items-center gap-1.5 rounded-xl px-3 py-2 text-sm font-semibold [&::-webkit-details-marker]:hidden">
        <ChevronDownIcon aria-hidden className="size-4 transition-transform group-open:rotate-180" />
        {summary}
      </summary>
      <div className="pt-3">{children}</div>
    </details>
  );
}
