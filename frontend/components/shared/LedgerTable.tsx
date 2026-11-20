import type { LedgerRow } from "@/lib/api/types";
import { ar } from "@/lib/copy/ar";
import { formatDate } from "@/lib/format/date";
import { formatMoney } from "@/lib/format/money";
import { cn } from "@/lib/utils";

const copy = ar.statement;

/** What the row refers to: the order, or the return of an order (BR-C08). */
export function ledgerReference(row: LedgerRow): string | null {
  if (row.order_number === null) {
    return null;
  }

  return row.return_number === null
    ? copy.orderRef(row.order_number)
    : copy.returnRef(row.order_number, row.return_number);
}

/**
 * A customer statement as a ledger (BR-C08), the same on the dashboard and the storefront: an opening balance line,
 * one row per change with its debit, credit and running balance, and the closing balance. Info rows (credit created)
 * show without amounts. Printable: no horizontal scroll and compact type on paper.
 */
export function LedgerTable({
  rows,
  opening,
  closing,
  orderHref,
}: {
  rows: readonly LedgerRow[];
  opening: string;
  closing: string;
  orderHref?: (number: number) => string;
}) {
  return (
    <div className="overflow-x-auto rounded-md border print:overflow-visible print:border-0">
      <table className="w-full min-w-[560px] border-collapse text-sm print:min-w-0 print:text-xs" data-testid="ledger">
        <thead>
          <tr className="bg-muted/60 text-start print:bg-transparent">
            <th className="p-2 text-start font-medium">{copy.date}</th>
            <th className="p-2 text-start font-medium">{copy.entry}</th>
            <th className="p-2 text-end font-medium">{copy.debit}</th>
            <th className="p-2 text-end font-medium">{copy.credit}</th>
            <th className="p-2 text-end font-medium">{copy.balance}</th>
          </tr>
        </thead>
        <tbody>
          <tr className="border-t">
            <td className="p-2" />
            <td className="text-muted-foreground p-2">{copy.opening}</td>
            <td className="p-2" />
            <td className="p-2" />
            <td className="p-2 text-end font-medium whitespace-nowrap" data-testid="ledger-opening">
              {formatMoney(opening)}
            </td>
          </tr>
          {rows.map((row, index) => {
            const reference = ledgerReference(row);
            const info = row.debit === "0.00" && row.credit === "0.00";

            return (
              <tr key={`${row.type}-${row.date}-${index}`} className={cn("border-t", info && "text-muted-foreground")}>
                <td className="p-2 whitespace-nowrap">{formatDate(row.date)}</td>
                <td className="p-2">
                  <span>{row.type_label}</span>
                  {row.method !== null && row.method !== "credit" && (
                    <span className="text-muted-foreground ms-2 text-xs" data-testid="ledger-method">
                      {row.method_label}
                      {row.reference !== null && <bdi dir="ltr"> · {row.reference}</bdi>}
                    </span>
                  )}
                  {reference !== null && (
                    <span className="text-muted-foreground ms-2 text-xs">
                      {orderHref !== undefined && row.order_number !== null ? (
                        <a href={orderHref(row.order_number)} className="hover:underline print:no-underline">
                          {reference}
                        </a>
                      ) : (
                        reference
                      )}
                    </span>
                  )}
                </td>
                <td className="p-2 text-end whitespace-nowrap">{row.debit === "0.00" ? "" : formatMoney(row.debit)}</td>
                <td className="p-2 text-end whitespace-nowrap">
                  {row.credit === "0.00" ? "" : formatMoney(row.credit)}
                </td>
                <td className="p-2 text-end font-medium whitespace-nowrap">{info ? "" : formatMoney(row.balance)}</td>
              </tr>
            );
          })}
          <tr className="border-t font-bold">
            <td className="p-2" />
            <td className="p-2">{copy.closing}</td>
            <td className="p-2" />
            <td className="p-2" />
            <td className="p-2 text-end whitespace-nowrap" data-testid="ledger-closing">
              {formatMoney(closing)}
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  );
}
