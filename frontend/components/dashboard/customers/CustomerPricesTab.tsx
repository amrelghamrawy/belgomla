"use client";

import { TrashIcon } from "lucide-react";
import { type FormEvent, useState } from "react";
import { toast } from "sonner";

import { ProductPicker } from "@/components/dashboard/orders/ProductPicker";
import { Button } from "@/components/ui/button";
import { FieldError } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import type { CustomerSkuPrice, DashboardProduct } from "@/lib/api/types";
import { ar, messageFor } from "@/lib/copy/ar";
import { useCustomerPrices, useRemoveCustomerPrice, useSetCustomerPrice } from "@/lib/dashboard/customer-pricing";
import { toPiasters } from "@/lib/dashboard/price-math";
import { formatMoney } from "@/lib/format/money";

import { Pager } from "../common/Pager";
import { EmptyBlock, ErrorBlock, LoadingBlock } from "../common/States";

const copy = ar.dashboard.customerPrices;

type Picked = { skuId: number; name: string; code: string; basePrice: string };

/**
 * «أسعار خاصة» (BR-PR13, V2 Task 11): the prices negotiated with this customer. Pick a product, type its price (it may be
 * above the normal price, D89) and an optional note; each row can be changed in place or removed.
 */
export function CustomerPricesTab({ customerId }: { customerId: number }) {
  const [q, setQ] = useState("");
  const [term, setTerm] = useState("");
  const [page, setPage] = useState(1);
  const prices = useCustomerPrices(customerId, { ...(term === "" ? {} : { q: term }), page });

  const search = (event: FormEvent) => {
    event.preventDefault();
    setTerm(q.trim());
    setPage(1);
  };

  return (
    <div className="flex flex-col gap-4">
      <p className="text-muted-foreground text-sm">{copy.description}</p>
      <AddPrice customerId={customerId} />
      <form onSubmit={search} role="search" className="flex gap-2">
        <Input
          aria-label={copy.search}
          placeholder={copy.search}
          value={q}
          onChange={(event) => setQ(event.target.value)}
          className="max-w-xs"
        />
        <Button type="submit" variant="outline">
          {ar.dashboard.list.search}
        </Button>
      </form>
      {prices.isError && <ErrorBlock error={prices.error} onRetry={() => void prices.refetch()} />}
      {prices.isPending && <LoadingBlock rows={3} />}
      {prices.data?.data.length === 0 && <EmptyBlock>{copy.empty}</EmptyBlock>}
      {prices.data !== undefined && prices.data.data.length > 0 && (
        <div className="overflow-x-auto rounded-md border">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>{copy.product}</TableHead>
                <TableHead>{copy.code}</TableHead>
                <TableHead>{copy.basePrice}</TableHead>
                <TableHead>{copy.price}</TableHead>
                <TableHead>{copy.note}</TableHead>
                <TableHead>
                  <span className="sr-only">{copy.remove}</span>
                </TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {prices.data.data.map((price) => (
                <PriceRow
                  key={`${price.sku_id}-${price.unit_price}-${price.note ?? ""}`}
                  customerId={customerId}
                  price={price}
                />
              ))}
            </TableBody>
          </Table>
        </div>
      )}
      <Pager meta={prices.data?.meta} onPage={setPage} />
    </div>
  );
}

/** A typed price as the request sends it, or null when it is not a positive amount with at most 2 decimals. */
export function negotiatedPrice(value: string): string | null {
  const clean = value.trim();
  const piasters = toPiasters(clean);

  return piasters === null || piasters <= BigInt(0) ? null : clean;
}

function AddPrice({ customerId }: { customerId: number }) {
  const set = useSetCustomerPrice(customerId);
  const [picked, setPicked] = useState<Picked | null>(null);
  const [price, setPrice] = useState("");
  const [note, setNote] = useState("");
  const [error, setError] = useState<string | null>(null);

  const pick = (product: DashboardProduct & { sku: NonNullable<DashboardProduct["sku"]> }) =>
    setPicked({ skuId: product.sku.id, name: product.name, code: product.sku.code, basePrice: product.sku.base_price });

  const submit = (event: FormEvent) => {
    event.preventDefault();
    const unitPrice = negotiatedPrice(price);

    if (picked === null) {
      setError(copy.pickProduct);
      return;
    }

    if (unitPrice === null) {
      setError(copy.invalidPrice);
      return;
    }

    setError(null);
    set.mutate(
      { skuId: picked.skuId, unit_price: unitPrice, note: note.trim() === "" ? null : note.trim() },
      {
        onSuccess: () => {
          toast.success(copy.saved);
          setPicked(null);
          setPrice("");
          setNote("");
        },
        onError: (failure) => setError(messageFor(failure)),
      },
    );
  };

  return (
    <form onSubmit={submit} noValidate className="flex flex-col gap-3 rounded-md border p-3" aria-label={copy.add}>
      <div className="flex flex-wrap items-center gap-2">
        <ProductPicker onPick={pick} />
        {picked !== null && (
          <span className="text-sm">
            {picked.name} · <bdi dir="ltr">{picked.code}</bdi> · {copy.basePrice}: {formatMoney(picked.basePrice)}
          </span>
        )}
      </div>
      <div className="grid gap-3 sm:grid-cols-2">
        <div className="flex flex-col gap-1">
          <Label htmlFor="price-new">{copy.price}</Label>
          <Input
            id="price-new"
            dir="ltr"
            inputMode="decimal"
            className="text-start"
            value={price}
            onChange={(event) => setPrice(event.target.value)}
          />
        </div>
        <div className="flex flex-col gap-1">
          <Label htmlFor="price-note">{copy.note}</Label>
          <Input
            id="price-note"
            maxLength={300}
            placeholder={copy.notePlaceholder}
            value={note}
            onChange={(event) => setNote(event.target.value)}
          />
        </div>
      </div>
      {error !== null && <FieldError role="alert">{error}</FieldError>}
      <Button type="submit" className="self-start" disabled={set.isPending}>
        {set.isPending ? ar.saving : copy.add}
      </Button>
    </form>
  );
}

function PriceRow({ customerId, price }: { customerId: number; price: CustomerSkuPrice }) {
  const set = useSetCustomerPrice(customerId);
  const remove = useRemoveCustomerPrice(customerId);
  const [value, setValue] = useState(price.unit_price);

  const save = () => {
    const unitPrice = negotiatedPrice(value);

    if (unitPrice === null) {
      toast.error(copy.invalidPrice);
      return;
    }

    set.mutate(
      { skuId: price.sku_id, unit_price: unitPrice, note: price.note },
      { onSuccess: () => toast.success(copy.saved), onError: (failure) => toast.error(messageFor(failure)) },
    );
  };

  return (
    <TableRow data-testid={`price-${price.sku_id}`}>
      <TableCell className="font-medium">{price.product_name}</TableCell>
      <TableCell>
        <bdi dir="ltr">{price.sku_code}</bdi>
      </TableCell>
      <TableCell className="whitespace-nowrap">
        {formatMoney(price.base_price)} / {price.unit}
      </TableCell>
      <TableCell>
        <div className="flex items-center gap-1">
          <Input
            aria-label={`${copy.price} ${price.product_name}`}
            dir="ltr"
            inputMode="decimal"
            className="w-28 text-start"
            value={value}
            onChange={(event) => setValue(event.target.value)}
          />
          {value !== price.unit_price && (
            <Button size="sm" variant="outline" disabled={set.isPending} onClick={save}>
              {ar.save}
            </Button>
          )}
        </div>
      </TableCell>
      <TableCell className="text-muted-foreground text-sm">{price.note ?? ar.dashboard.none}</TableCell>
      <TableCell>
        <Button
          variant="ghost"
          size="icon"
          aria-label={`${copy.remove} ${price.product_name}`}
          disabled={remove.isPending}
          onClick={() =>
            remove.mutate(price.sku_id, {
              onSuccess: () => toast.success(copy.removed),
              onError: (failure) => toast.error(messageFor(failure)),
            })
          }
        >
          <TrashIcon />
        </Button>
      </TableCell>
    </TableRow>
  );
}
