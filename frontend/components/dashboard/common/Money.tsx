import type { Money as MoneyValue } from "@/lib/api/types";
import { ar } from "@/lib/copy/ar";
import { formatMoney } from "@/lib/format/money";
import { cn } from "@/lib/utils";

/**
 * D104: an amount in a table or list, with the currency set small and muted so a column reads as numbers, and a
 * zero dimmed so the rows that carry money stand out. The text stays «1,250.50 ج.م» for readers and copy-paste.
 */
export function Money({ value, className }: { value: MoneyValue; className?: string }) {
  const text = formatMoney(value);
  const amount = text.slice(0, text.length - ar.currency.length - 1);
  const zero = /^-?0\.00$/.test(value);

  return (
    <bdi className={cn("whitespace-nowrap tabular-nums", zero && "text-muted-foreground font-normal", className)}>
      {amount} <span className="text-muted-foreground text-[0.75em] font-normal">{ar.currency}</span>
    </bdi>
  );
}
