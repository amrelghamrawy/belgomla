import { TrendingDownIcon, TrendingUpIcon } from "lucide-react";
import type { ReactNode } from "react";

import { ar } from "@/lib/copy/ar";
import { formatDelta } from "@/lib/dashboard/report-range";
import { cn } from "@/lib/utils";

const copy = ar.dashboard.reports;

type Props = {
  label: string;
  value: ReactNode;
  /** The change against the previous period in %, null when there is nothing to compare. */
  delta?: number | null;
  /** Whether a rise is good news (sales) or bad (returns, discounts); colours the change. */
  upIsGood?: boolean;
  hint?: ReactNode;
  testId?: string;
};

/** A headline number of a report, with its change against the previous period (icon + text, never colour alone). */
export function KpiTile({ label, value, delta = null, upIsGood = true, hint, testId }: Props) {
  const good = delta !== null && delta !== 0 && delta > 0 === upIsGood;
  const Icon = delta !== null && delta < 0 ? TrendingDownIcon : TrendingUpIcon;

  return (
    <div className="bg-card shadow-panel flex min-w-0 flex-col gap-1 rounded-[20px] px-5 py-4">
      <p className="text-muted-foreground text-xs">{label}</p>
      <p
        className="font-heading text-foreground text-lg font-bold tabular-nums sm:text-[1.4rem] sm:whitespace-nowrap"
        data-testid={testId}
      >
        {value}
      </p>
      {delta !== null && (
        <p
          className={cn(
            "flex items-center gap-1 text-xs",
            delta === 0 ? "text-muted-foreground" : good ? "text-ok" : "text-destructive",
          )}
          data-testid={testId === undefined ? undefined : `${testId}-delta`}
        >
          {delta !== 0 && <Icon aria-hidden className="size-3.5" />}
          <bdi>{formatDelta(delta)}</bdi> {copy.vsPrevious}
        </p>
      )}
      {hint !== undefined && <div className="text-muted-foreground text-xs">{hint}</div>}
    </div>
  );
}
