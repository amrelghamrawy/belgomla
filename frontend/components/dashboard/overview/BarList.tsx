import Link from "next/link";

import { cn } from "@/lib/utils";

export type BarItem = {
  key: string;
  label: string;
  value: number;
  /** The value as shown next to the bar (formatted money, a count). */
  display: string;
  /** The list behind the row; without one the row is a plain bar (a report breakdown). */
  href?: string;
  /** A recessive bar for an item that is not part of the story (cancelled orders). */
  muted?: boolean;
};

/**
 * A ranked horizontal bar list: one hue, one shared scale from zero, the label and value as text beside the
 * bar (text never wears the bar's color), and every row a link to the list behind it. Bars grow from the
 * start edge, so they read right to left in RTL.
 */
export function BarList({ items, label, testId }: { items: BarItem[]; label: string; testId?: string }) {
  const max = Math.max(...items.map((item) => item.value), 0);

  return (
    <ul aria-label={label} className="flex flex-col gap-1" data-testid={testId}>
      {items.map((item) => (
        <li key={item.key}>
          {item.href === undefined ? (
            <div
              data-testid={testId === undefined ? undefined : `${testId}-${item.key}`}
              className="flex flex-col gap-1 px-2 py-1.5"
            >
              <Row item={item} max={max} />
            </div>
          ) : (
            <Link
              href={item.href}
              data-testid={testId === undefined ? undefined : `${testId}-${item.key}`}
              className="hover:bg-muted flex flex-col gap-1 rounded-md px-2 py-1.5"
            >
              <Row item={item} max={max} />
            </Link>
          )}
        </li>
      ))}
    </ul>
  );
}

function Row({ item, max }: { item: BarItem; max: number }) {
  return (
    <>
      <span className="flex items-baseline justify-between gap-2 text-sm">
        <span className="min-w-0 truncate">{item.label}</span>
        <span className="font-semibold whitespace-nowrap tabular-nums">{item.display}</span>
      </span>
      <span className="bg-muted block h-2 overflow-hidden rounded-full" aria-hidden>
        <span
          className={cn("block h-full rounded-full", item.muted ? "bg-muted-foreground/40" : "bg-primary")}
          style={{ width: max === 0 ? 0 : `${Math.max((item.value / max) * 100, item.value > 0 ? 2 : 0)}%` }}
        />
      </span>
    </>
  );
}
