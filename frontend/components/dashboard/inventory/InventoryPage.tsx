"use client";

import Link from "next/link";
import { type FormEvent, useState } from "react";
import { toast } from "sonner";

import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { FieldError } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { NativeSelect } from "@/components/ui/native-select";
import { Switch } from "@/components/ui/switch";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import type { BulkAdjustResult, InventoryRow } from "@/lib/api/types";
import { ar, messageFor } from "@/lib/copy/ar";
import { INVENTORY_LIST_KEYS, useBulkAdjustStock, useInventory } from "@/lib/dashboard/inventory";
import { activeFilters, hasFilters } from "@/lib/dashboard/list-query";
import { useListQuery } from "@/lib/dashboard/use-list-query";

import { FilterPanel } from "../common/FilterPanel";
import { Pager } from "../common/Pager";
import { EmptyBlock, ErrorBlock, LoadingBlock, PageHeader } from "../common/States";
import { AdjustStockSheet } from "./AdjustStockSheet";

const copy = ar.dashboard.inventory;

/** Stock per SKU with on hand, reserved, available and the minimum allowed; adjust one or many. */
export function InventoryPage() {
  const [query, setQuery] = useListQuery(INVENTORY_LIST_KEYS);
  const inventory = useInventory(query);
  const [adjusting, setAdjusting] = useState<InventoryRow | null>(null);
  const [bulk, setBulk] = useState(false);
  const [q, setQ] = useState(query.q ?? "");
  const rows = inventory.data?.data ?? [];

  const search = (event: FormEvent) => {
    event.preventDefault();
    setQuery({ q: q.trim() });
  };

  return (
    <>
      <PageHeader
        title={copy.title}
        description={copy.description}
        actions={
          <Button variant={bulk ? "default" : "outline"} onClick={() => setBulk(!bulk)} aria-pressed={bulk}>
            {copy.bulk}
          </Button>
        }
      />
      <FilterPanel
        active={activeFilters(query, ["sort", "low_stock"])}
        onClear={
          hasFilters(query)
            ? () => {
                setQ("");
                setQuery(Object.fromEntries(Object.keys(query).map((key) => [key, null])));
              }
            : undefined
        }
        leading={
          <form onSubmit={search} role="search" className="flex gap-2">
            <Input
              aria-label={ar.dashboard.list.search}
              placeholder={ar.dashboard.list.searchPlaceholder}
              value={q}
              onChange={(event) => setQ(event.target.value)}
              className="max-w-xs"
            />
            <Button type="submit" variant="outline">
              {ar.dashboard.list.search}
            </Button>
          </form>
        }
      >
        <NativeSelect
          aria-label={copy.sort}
          value={query.sort ?? "code"}
          onChange={(event) => setQuery({ sort: event.target.value === "code" ? null : event.target.value })}
        >
          {Object.entries(copy.sorts).map(([value, label]) => (
            <option key={value} value={value}>
              {label}
            </option>
          ))}
        </NativeSelect>
        <div className="flex h-10 items-center gap-2">
          <Switch
            id="inventory-low-stock"
            checked={query.low_stock === "1"}
            onCheckedChange={(checked) => setQuery({ low_stock: checked ? "1" : null })}
          />
          <Label htmlFor="inventory-low-stock">{copy.lowStockOnly}</Label>
        </div>
      </FilterPanel>
      {inventory.isError && <ErrorBlock error={inventory.error} onRetry={() => void inventory.refetch()} />}
      {inventory.isPending && <LoadingBlock />}
      {inventory.data !== undefined && rows.length === 0 && <EmptyBlock>{copy.empty}</EmptyBlock>}
      {rows.length > 0 &&
        (bulk ? (
          <BulkAdjustTable rows={rows} onDone={() => setBulk(false)} />
        ) : (
          <div className="overflow-x-auto rounded-md border">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>{copy.code}</TableHead>
                  <TableHead>{copy.name}</TableHead>
                  <TableHead>{copy.onHand}</TableHead>
                  <TableHead>{copy.reserved}</TableHead>
                  <TableHead>{copy.available}</TableHead>
                  <TableHead>{copy.minAllowed}</TableHead>
                  <TableHead>{ar.dashboard.list.actions}</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {rows.map((row) => (
                  <TableRow key={row.sku_id} data-testid={`stock-${row.sku_id}`}>
                    <TableCell>
                      <bdi>{row.code}</bdi>
                    </TableCell>
                    <TableCell className="max-w-56">{row.name ?? ar.dashboard.none}</TableCell>
                    <TableCell>{row.on_hand}</TableCell>
                    <TableCell>{row.reserved}</TableCell>
                    <TableCell className="whitespace-nowrap">
                      {row.available}
                      {row.low_stock && (
                        <Badge variant="destructive" className="ms-2">
                          {copy.lowStock}
                        </Badge>
                      )}
                    </TableCell>
                    <TableCell>{row.min_allowed}</TableCell>
                    <TableCell className="flex gap-1">
                      <Button variant="outline" size="sm" onClick={() => setAdjusting(row)}>
                        {copy.adjust}
                      </Button>
                      <Button asChild variant="ghost" size="sm">
                        <Link href={`/dashboard/inventory/${row.sku_id}`}>{copy.movements}</Link>
                      </Button>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
        ))}
      <Pager meta={inventory.data?.meta} onPage={(page) => setQuery({ page })} />
      {adjusting !== null && <AdjustStockSheet row={adjusting} onClose={() => setAdjusting(null)} />}
    </>
  );
}

/**
 * The bulk form: a new on-hand per row of this page and one reason; rows left empty are not sent. The response
 * lists what was skipped and why (BR-INV08: below_reserved with the minimum, not_found, unchanged).
 */
export function BulkAdjustTable({ rows, onDone }: { rows: InventoryRow[]; onDone: () => void }) {
  const bulk = useBulkAdjustStock();
  const [values, setValues] = useState<Record<number, string>>({});
  const [reason, setReason] = useState("");
  const [problem, setProblem] = useState<string | null>(null);
  const [result, setResult] = useState<BulkAdjustResult | null>(null);
  const codes = new Map(rows.map((row) => [row.sku_id, row.code]));

  const submit = (event: FormEvent) => {
    event.preventDefault();
    const items = Object.entries(values)
      .filter(([, value]) => value.trim() !== "")
      .map(([skuId, value]) => ({ sku_id: Number(skuId), new_on_hand: Number(value), reason: reason.trim() }));

    if (items.length === 0) {
      setProblem(copy.bulkNothing);
      return;
    }

    if (items.some((item) => !Number.isInteger(item.new_on_hand) || item.new_on_hand < 0)) {
      setProblem(ar.dashboard.productForm.invalidInteger);
      return;
    }

    if (reason.trim() === "") {
      setProblem(ar.dashboard.reasonRequired);
      return;
    }

    setProblem(null);
    bulk.mutate(items, {
      onSuccess: (outcome) => {
        setResult(outcome);
        toast.success(copy.bulkApplied(outcome.applied));

        if (outcome.skipped.length === 0) {
          onDone();
        }
      },
      onError: (failure) => setProblem(messageFor(failure)),
    });
  };

  return (
    <form onSubmit={submit} noValidate className="flex flex-col gap-3">
      <p className="text-muted-foreground text-sm">{copy.bulkHint}</p>
      <div className="overflow-x-auto rounded-md border">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>{copy.code}</TableHead>
              <TableHead>{copy.name}</TableHead>
              <TableHead>{copy.onHand}</TableHead>
              <TableHead>{copy.minAllowed}</TableHead>
              <TableHead>{copy.newOnHand}</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {rows.map((row) => (
              <TableRow key={row.sku_id}>
                <TableCell>
                  <bdi>{row.code}</bdi>
                </TableCell>
                <TableCell className="max-w-56">{row.name}</TableCell>
                <TableCell>{row.on_hand}</TableCell>
                <TableCell>{row.min_allowed}</TableCell>
                <TableCell>
                  <Input
                    aria-label={`${copy.newOnHand} ${row.code}`}
                    dir="ltr"
                    inputMode="numeric"
                    className="w-28 text-start"
                    value={values[row.sku_id] ?? ""}
                    onChange={(event) => setValues({ ...values, [row.sku_id]: event.target.value })}
                  />
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>
      <div className="flex flex-wrap items-end gap-2">
        <div className="flex min-w-60 flex-1 flex-col gap-1">
          <Label htmlFor="bulk-reason">{copy.bulkReason}</Label>
          <Input id="bulk-reason" maxLength={200} value={reason} onChange={(event) => setReason(event.target.value)} />
        </div>
        <Button type="submit" disabled={bulk.isPending}>
          {bulk.isPending ? ar.saving : copy.bulkApply}
        </Button>
      </div>
      {problem !== null && <FieldError role="alert">{problem}</FieldError>}
      {result !== null && result.skipped.length > 0 && (
        <Alert data-testid="bulk-skipped">
          <AlertDescription>
            <p>{copy.bulkSkipped}</p>
            <ul className="list-disc ps-5">
              {result.skipped.map((skipped) => (
                <li key={skipped.sku_id}>
                  <bdi>{codes.get(skipped.sku_id) ?? skipped.sku_id}</bdi>:{" "}
                  {skipped.reason === "below_reserved"
                    ? copy.skipReasons.below_reserved(skipped.min_allowed ?? 0)
                    : copy.skipReasons[skipped.reason]}
                </li>
              ))}
            </ul>
          </AlertDescription>
        </Alert>
      )}
    </form>
  );
}
