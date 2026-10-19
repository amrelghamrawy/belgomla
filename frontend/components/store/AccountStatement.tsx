"use client";

import { ChevronRightIcon } from "lucide-react";
import Link from "next/link";
import { usePathname, useRouter, useSearchParams } from "next/navigation";

import { LedgerTable } from "@/components/shared/LedgerTable";
import { StatusPage } from "@/components/shared/StatusPage";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import { useRequiredCustomer } from "@/lib/auth/session";
import { ar, messageFor } from "@/lib/copy/ar";
import { formatMoney } from "@/lib/format/money";
import { useAccountStatement } from "@/lib/store/statement";
import { cn } from "@/lib/utils";

const copy = ar.statement;

/**
 * `/account/statement` (BR-C08): the customer's own ledger — what they owe, their limit and credit, what is overdue —
 * with a date range kept in the URL and a print view.
 */
export function AccountStatement() {
  const session = useRequiredCustomer();
  const params = useSearchParams();
  const router = useRouter();
  const pathname = usePathname();
  const from = params.get("from") ?? undefined;
  const to = params.get("to") ?? undefined;
  const page = Number(params.get("page") ?? "1") || 1;
  const statement = useAccountStatement({ from, to, page }, session.data !== undefined && session.data !== null);

  const setParam = (key: string, value: string) => {
    const next = new URLSearchParams(params.toString());

    if (value === "") {
      next.delete(key);
    } else {
      next.set(key, value);
    }

    next.delete("page");
    router.replace(`${pathname}${next.size > 0 ? `?${next.toString()}` : ""}`, { scroll: false });
  };

  if (!session.data) {
    return null;
  }

  if (statement.error) {
    return <StatusPage title={ar.pages.errorTitle} body={messageFor(statement.error)} />;
  }

  return (
    <main className="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-4 px-4 py-6 print:max-w-none print:p-0">
      <Button asChild variant="link" className="self-start px-0 print:hidden">
        <Link href="/account">
          <ChevronRightIcon className="rtl:rotate-180" />
          {ar.account.title}
        </Link>
      </Button>
      <h1 className="text-xl font-bold">{copy.title}</h1>
      {statement.data === undefined ? (
        <Skeleton className="h-64" />
      ) : (
        <>
          <dl className="grid grid-cols-2 gap-3 sm:grid-cols-4" data-testid="account-statement-summary">
            {(
              [
                [copy.outstanding, statement.data.summary.outstanding, false],
                [copy.overdue, statement.data.summary.overdue, statement.data.summary.overdue !== "0.00"],
                [copy.creditBalance, statement.data.summary.credit_balance, false],
                [copy.availableCredit, statement.data.summary.available_credit, false],
              ] as const
            )
              .filter(([, value]) => value !== null)
              .map(([label, value, alert]) => (
                <div key={label} className="rounded-xl border p-3">
                  <dt className="text-muted-foreground text-xs">{label}</dt>
                  <dd className={cn("text-lg font-bold", alert && "text-destructive")}>
                    {formatMoney(value ?? "0.00")}
                  </dd>
                </div>
              ))}
          </dl>
          <div className="flex flex-wrap items-end gap-2 print:hidden">
            <label className="flex flex-col gap-1 text-xs">
              {copy.from}
              <Input type="date" value={from ?? ""} onChange={(event) => setParam("from", event.target.value)} />
            </label>
            <label className="flex flex-col gap-1 text-xs">
              {copy.to}
              <Input type="date" value={to ?? ""} onChange={(event) => setParam("to", event.target.value)} />
            </label>
            <Button variant="outline" className="h-11" onClick={() => window.print()}>
              {copy.print}
            </Button>
          </div>
          {statement.data.data.length === 0 && statement.data.meta.opening_balance === "0.00" ? (
            <p className="text-muted-foreground rounded-xl border p-6 text-center text-sm">{copy.empty}</p>
          ) : (
            <LedgerTable
              rows={statement.data.data}
              opening={statement.data.meta.opening_balance}
              closing={statement.data.meta.closing_balance}
              orderHref={(number) => `/account/orders/${number}`}
            />
          )}
          {statement.data.meta.last_page > 1 && (
            <div className="flex justify-between print:hidden">
              <Button variant="outline" disabled={page <= 1} onClick={() => setParam("page", String(page - 1))}>
                {copy.previous}
              </Button>
              <Button
                variant="outline"
                disabled={page >= statement.data.meta.last_page}
                onClick={() => setParam("page", String(page + 1))}
              >
                {copy.next}
              </Button>
            </div>
          )}
        </>
      )}
    </main>
  );
}
