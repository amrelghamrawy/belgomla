"use client";

import { ChevronRightIcon, TrashIcon } from "lucide-react";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { useEffect, useMemo, useState } from "react";
import { toast } from "sonner";

import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardAction, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { NativeSelect } from "@/components/ui/native-select";
import { Switch } from "@/components/ui/switch";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { Textarea } from "@/components/ui/textarea";
import type { DashboardCustomer, OrderQuote, OrderQuoteLine } from "@/lib/api/types";
import { ar, messageFor } from "@/lib/copy/ar";
import { type CustomerAddressInput, useCustomer, useUpdateCustomer } from "@/lib/dashboard/customers";
import {
  addLine,
  discountedQuote,
  type DraftLine,
  orderLinesBody,
  QUOTE_DELAY_MS,
  quoteBody,
  quotedLine,
  quotePasses,
  removeLine,
  setQuantity,
  useOrderQuote,
  usePlaceOrder,
} from "@/lib/dashboard/new-order";
import { useCan } from "@/lib/dashboard/permissions";
import { type DiscountDraft, discountBody, hasDiscount, NO_DISCOUNT } from "@/lib/dashboard/price-math";
import { formatMoney } from "@/lib/format/money";
import { formatPhone } from "@/lib/format/phone";

import { CustomerPicker } from "../common/CustomerPicker";
import { PageHeader } from "../common/States";
import { AddressDialog, addressInput } from "../customers/AddressDialog";
import { CustomerStatusBadge } from "../customers/CustomerStatusBadge";
import { DiscountInput } from "./DiscountInput";
import { ProductPicker } from "./ProductPicker";

const copy = ar.dashboard.orders.newOrderForm;

/** A positive whole number from the URL (`?customer=12`, the customer page's shortcut), else null. */
function idFrom(value: string | null): number | null {
  const id = Number(value);

  return Number.isInteger(id) && id > 0 ? id : null;
}

/** The value after a pause in changes, so the quote is not asked for on every keystroke. */
function useSettled<T>(value: T): T {
  const [settled, setSettled] = useState(value);

  useEffect(() => {
    const timer = setTimeout(() => setSettled(value), QUOTE_DELAY_MS);

    return () => clearTimeout(timer);
  }, [value]);

  return settled;
}

/**
 * BR-O11: the merchant places an order for a customer (a phone or WhatsApp order): customer → address → products → the
 * live quote (the checkout's prices, tiers, stock, MOV and credit limit) → note, the customer message and, for the owner
 * or a manager, the MOV override (D84) → discounts per line and on the order for users with `orders.discount` (D85,
 * applied after the checks at list prices) → the order page.
 */
export function NewOrderPage() {
  const searchParams = useSearchParams();
  const router = useRouter();
  const allowed = useCan();
  const [customerId, setCustomerId] = useState<number | null>(() => idFrom(searchParams.get("customer")));
  const [addressId, setAddressId] = useState<number | null>(null);
  const [lines, setLines] = useState<DraftLine[]>([]);
  const [note, setNote] = useState("");
  const [notify, setNotify] = useState(true);
  const [overrideMov, setOverrideMov] = useState(false);
  const [lineDiscounts, setLineDiscounts] = useState<Record<number, DiscountDraft>>({});
  const [orderDiscount, setOrderDiscount] = useState<DiscountDraft>(NO_DISCOUNT);
  const [discountReason, setDiscountReason] = useState("");
  const [error, setError] = useState<string | null>(null);
  const customer = useCustomer(customerId ?? 0, customerId !== null);
  const body = useMemo(() => quoteBody(customerId, lines), [customerId, lines]);
  const quote = useOrderQuote(useSettled(body));
  const place = usePlaceOrder();

  const addresses = customer.data?.addresses ?? [];
  const chosenAddress = addressId ?? addresses.find((address) => address.is_default)?.id ?? addresses[0]?.id ?? null;
  const shownQuote = body === null ? undefined : quote.data;
  const active = customer.data?.status === "active";
  const canOverride = allowed("orders.override_mov");
  const canDiscount = allowed("orders.discount");
  const discounting =
    canDiscount && (hasDiscount(orderDiscount) || lines.some((line) => hasDiscount(lineDiscounts[line.sku_id])));
  const discounted = discounting ? discountedQuote(shownQuote, lines, lineDiscounts, orderDiscount) : null;
  const discountsValid =
    !discounting || (discounted?.order_total !== null && (!hasDiscount(orderDiscount) || discountReason.trim() !== ""));
  const ready =
    active &&
    chosenAddress !== null &&
    body !== null &&
    shownQuote !== undefined &&
    !quote.isFetching &&
    quotePasses(shownQuote, canOverride && overrideMov) &&
    discountsValid;
  const submit = () => {
    if (customerId === null || chosenAddress === null) {
      return;
    }

    setError(null);
    place.mutate(
      {
        customer_id: customerId,
        address_id: chosenAddress,
        lines: canDiscount
          ? orderLinesBody(lines, lineDiscounts)
          : lines.map(({ sku_id, quantity }) => ({ sku_id, quantity })),
        ...(canDiscount && hasDiscount(orderDiscount)
          ? { discount: { ...discountBody(orderDiscount), reason: discountReason.trim() } }
          : {}),
        note: note.trim() === "" ? null : note.trim(),
        notify_customer: notify,
        override_mov: canOverride && overrideMov && shownQuote?.mov.satisfied === false,
      },
      {
        onSuccess: (order) => {
          toast.success(copy.placed(order.number));
          router.push(`/dashboard/orders/${order.number}`);
        },
        onError: (failure) => setError(messageFor(failure)),
      },
    );
  };

  return (
    <>
      <Button asChild variant="link" className="self-start px-0">
        <Link href="/dashboard/orders">
          <ChevronRightIcon className="rtl:rotate-180" />
          {ar.dashboard.orders.back}
        </Link>
      </Button>
      <PageHeader title={copy.title} description={copy.hint} />
      <div className="grid grid-cols-1 gap-4 pb-20 md:pb-0 lg:grid-cols-3">
        <div className="flex min-w-0 flex-col gap-4 lg:col-span-2">
          <Card>
            <CardHeader>
              <CardTitle>{copy.customer}</CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-3">
              <CustomerPicker
                value={customerId === null ? undefined : String(customerId)}
                status="active"
                placeholder={ar.dashboard.customerPicker.choose}
                onChange={(id) => {
                  setCustomerId(idFrom(id));
                  setAddressId(null);
                  setOverrideMov(false);
                }}
              />
              {customer.data !== undefined && (
                <CustomerSummary
                  customer={customer.data}
                  addressId={chosenAddress}
                  onAddress={setAddressId}
                  onAdded={setAddressId}
                />
              )}
            </CardContent>
          </Card>
          <Card>
            <CardHeader>
              <CardTitle>{copy.products}</CardTitle>
              <CardAction>
                <ProductPicker
                  onPick={(product) =>
                    setLines((current) =>
                      addLine(current, {
                        sku_id: product.sku.id,
                        quantity: product.sku.moq,
                        name: product.name,
                        code: product.sku.code,
                      }),
                    )
                  }
                />
              </CardAction>
            </CardHeader>
            <CardContent>
              {lines.length === 0 ? (
                <p className="text-muted-foreground text-sm">{copy.noLines}</p>
              ) : (
                <LinesTable
                  lines={lines}
                  quote={shownQuote}
                  discounts={canDiscount ? lineDiscounts : null}
                  priced={discounted?.lines ?? null}
                  onDiscount={(skuId, draft) => setLineDiscounts((current) => ({ ...current, [skuId]: draft }))}
                  onQuantity={(skuId, quantity) => setLines((current) => setQuantity(current, skuId, quantity))}
                  onRemove={(skuId) => setLines((current) => removeLine(current, skuId))}
                />
              )}
            </CardContent>
          </Card>
        </div>
        <Card className="h-fit">
          <CardHeader>
            <CardTitle>{copy.itemsTotal}</CardTitle>
            <CardAction>
              <span className="text-lg font-bold" data-testid="quote-total">
                {formatMoney(shownQuote?.items_total ?? "0.00")}
              </span>
            </CardAction>
          </CardHeader>
          <CardContent className="flex flex-col gap-3">
            {quote.isFetching && body !== null && <p className="text-muted-foreground text-xs">{copy.checking}</p>}
            {quote.isError && body !== null && (
              <Alert variant="destructive">
                <AlertDescription>{messageFor(quote.error)}</AlertDescription>
              </Alert>
            )}
            {shownQuote !== undefined && <QuoteAlerts quote={shownQuote} />}
            {canDiscount && (
              <div className="flex flex-col gap-2 rounded-md border p-3" data-testid="new-order-discounts">
                <Label htmlFor="new-order-discount">{ar.dashboard.orders.discount.order}</Label>
                <DiscountInput
                  id="new-order-discount"
                  label={ar.dashboard.orders.discount.order}
                  draft={orderDiscount}
                  invalid={
                    discounted !== null && discounted.items_total !== null && discounted.discount_amount === null
                  }
                  onChange={setOrderDiscount}
                />
                {hasDiscount(orderDiscount) && (
                  <>
                    <Label htmlFor="new-order-discount-reason">{ar.dashboard.orders.discount.reason}</Label>
                    <Textarea
                      id="new-order-discount-reason"
                      rows={2}
                      maxLength={300}
                      value={discountReason}
                      onChange={(event) => setDiscountReason(event.target.value)}
                    />
                  </>
                )}
                <span className="text-muted-foreground text-xs">{copy.discountsHint}</span>
                {discounting && (
                  <span className="flex justify-between text-sm font-bold">
                    <span>{copy.totalAfterDiscount}</span>
                    <span data-testid="discounted-total">
                      {discounted?.order_total == null ? "—" : formatMoney(discounted.order_total)}
                    </span>
                  </span>
                )}
              </div>
            )}
            {shownQuote !== undefined && !shownQuote.mov.satisfied && canOverride && (
              <div className="flex items-start gap-2">
                <Checkbox
                  id="override-mov"
                  checked={overrideMov}
                  onCheckedChange={(checked) => setOverrideMov(checked === true)}
                />
                <div className="flex flex-col gap-0.5">
                  <Label htmlFor="override-mov">{copy.overrideMov}</Label>
                  <span className="text-muted-foreground text-xs">{copy.overrideMovHint}</span>
                </div>
              </div>
            )}
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="order-note">{copy.note}</Label>
              <Textarea
                id="order-note"
                maxLength={1000}
                value={note}
                onChange={(event) => setNote(event.target.value)}
              />
            </div>
            <div className="flex items-start gap-2">
              <Switch id="notify-customer" checked={notify} onCheckedChange={setNotify} />
              <div className="flex flex-col gap-0.5">
                <Label htmlFor="notify-customer">{copy.notify}</Label>
                <span className="text-muted-foreground text-xs">{copy.notifyHint}</span>
              </div>
            </div>
            {error !== null && (
              <Alert variant="destructive" role="alert">
                <AlertDescription>{error}</AlertDescription>
              </Alert>
            )}
            <div className="bg-background/95 fixed inset-x-0 bottom-0 z-30 border-t p-3 backdrop-blur md:static md:border-0 md:bg-transparent md:p-0">
              <Button type="button" className="h-11 w-full" disabled={!ready || place.isPending} onClick={submit}>
                {place.isPending ? copy.submitting : copy.submit}
              </Button>
            </div>
          </CardContent>
        </Card>
      </div>
    </>
  );
}

/** The chosen customer's phone and status, and the delivery address (BR-CU03), with an inline "add address". */
function CustomerSummary({
  customer,
  addressId,
  onAddress,
  onAdded,
}: {
  customer: DashboardCustomer;
  addressId: number | null;
  onAddress: (id: number) => void;
  onAdded: (id: number) => void;
}) {
  const allowed = useCan();
  const [adding, setAdding] = useState(false);
  const update = useUpdateCustomer(customer.id);
  const addresses = customer.addresses ?? [];

  const save = (address: CustomerAddressInput) => {
    const known = new Set(addresses.map((current) => current.id));
    const rest = addresses.map(addressInput);

    update.mutate(
      {
        name: customer.name,
        addresses: [...(address.is_default ? rest.map((row) => ({ ...row, is_default: false })) : rest), address],
      },
      {
        onSuccess: (saved) => {
          setAdding(false);
          toast.success(copy.addressAdded);
          const added = (saved.addresses ?? []).find((row) => !known.has(row.id));

          if (added !== undefined) {
            onAdded(added.id);
          }
        },
        onError: (failure) => toast.error(messageFor(failure)),
      },
    );
  };

  return (
    <div className="flex flex-col gap-3">
      <span className="flex flex-wrap items-center gap-2 text-sm">
        <bdi>{formatPhone(customer.phone)}</bdi>
        <CustomerStatusBadge status={customer.status} />
      </span>
      {customer.status !== "active" && (
        <Alert variant="destructive">
          <AlertDescription>
            {copy.inactiveCustomer} «{ar.dashboard.customerStatuses[customer.status]}»
          </AlertDescription>
        </Alert>
      )}
      <div className="flex flex-col gap-1.5">
        <Label htmlFor="order-address">{copy.address}</Label>
        {addresses.length === 0 ? (
          <p className="text-muted-foreground text-sm">{copy.noAddress}</p>
        ) : (
          <NativeSelect
            id="order-address"
            value={addressId === null ? "" : String(addressId)}
            onChange={(event) => onAddress(Number(event.target.value))}
          >
            {addresses.map((address) => (
              <option key={address.id} value={address.id}>
                {[address.label, address.city, address.address_line].filter(Boolean).join(" — ")}
              </option>
            ))}
          </NativeSelect>
        )}
        {allowed("customers.manage") && (
          <Button type="button" variant="link" className="self-start px-0" onClick={() => setAdding(true)}>
            {copy.addAddress}
          </Button>
        )}
      </div>
      {adding && <AddressDialog address={null} onClose={() => setAdding(false)} onSave={save} />}
    </div>
  );
}

/** The lines with the quote's price, tier, stock and problem next to each (BR-PR04, BR-MOQ01, BR-INV01). */
function LinesTable({
  lines,
  quote,
  discounts,
  priced,
  onDiscount,
  onQuantity,
  onRemove,
}: {
  lines: DraftLine[];
  quote: OrderQuote | undefined;
  /** The line discounts, or null when the user may not discount (D85). */
  discounts: Record<number, DiscountDraft> | null;
  /** The prices after the discounts, by line, while any discount is typed. */
  priced: ({ unit_price: string; line_total: string } | null)[] | null;
  onDiscount: (skuId: number, draft: DiscountDraft) => void;
  onQuantity: (skuId: number, quantity: number) => void;
  onRemove: (skuId: number) => void;
}) {
  const orders = ar.dashboard.orders;

  return (
    <div className="overflow-x-auto rounded-md border">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>{orders.product}</TableHead>
            <TableHead>{orders.quantity}</TableHead>
            <TableHead>{orders.unitPrice}</TableHead>
            <TableHead>{orders.lineTotal}</TableHead>
            {discounts !== null && <TableHead>{orders.discount.button}</TableHead>}
            <TableHead>
              <span className="sr-only">{copy.remove}</span>
            </TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {lines.map((line, index) => {
            const quoted = quotedLine(quote, line.sku_id);
            const after = priced?.[index];
            const draft = discounts?.[line.sku_id] ?? NO_DISCOUNT;
            const shown = hasDiscount(draft) && after ? after : null;

            return (
              <TableRow key={line.sku_id} data-testid={`line-${line.sku_id}`}>
                <TableCell className="min-w-44 align-top">
                  <div className="flex flex-col gap-1">
                    <span className="font-medium">{line.name}</span>
                    <bdi dir="ltr" className="text-muted-foreground text-start text-xs">
                      {line.code}
                    </bdi>
                    {quoted !== undefined && <LineHints line={quoted} />}
                  </div>
                </TableCell>
                <TableCell className="align-top">
                  <Input
                    type="number"
                    inputMode="numeric"
                    min={1}
                    step={1}
                    aria-label={copy.quantityOf(line.name)}
                    className="w-24"
                    value={line.quantity === 0 ? "" : line.quantity}
                    onChange={(event) => onQuantity(line.sku_id, Number(event.target.value))}
                  />
                </TableCell>
                <TableCell className="align-top whitespace-nowrap">
                  {quoted === undefined ? (
                    "—"
                  ) : shown ? (
                    <>
                      <s className="text-muted-foreground me-1 text-xs">{formatMoney(quoted.unit_price)}</s>
                      {formatMoney(shown.unit_price)}
                    </>
                  ) : (
                    formatMoney(quoted.unit_price)
                  )}
                </TableCell>
                <TableCell className="align-top whitespace-nowrap">
                  {quoted === undefined ? "—" : formatMoney(shown?.line_total ?? quoted.line_total)}
                </TableCell>
                {discounts !== null && (
                  <TableCell className="align-top">
                    <DiscountInput
                      id={`line-discount-${line.sku_id}`}
                      label={line.name}
                      draft={draft}
                      invalid={hasDiscount(draft) && priced !== null && after === null}
                      onChange={(next) => onDiscount(line.sku_id, next)}
                    />
                  </TableCell>
                )}
                <TableCell className="align-top">
                  <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    aria-label={`${copy.remove}: ${line.name}`}
                    onClick={() => onRemove(line.sku_id)}
                  >
                    <TrashIcon />
                  </Button>
                </TableCell>
              </TableRow>
            );
          })}
        </TableBody>
      </Table>
    </div>
  );
}

function LineHints({ line }: { line: OrderQuoteLine }) {
  const reasons = ar.checkout.reasons;
  const problem = line.problem;

  return (
    <>
      <span className="text-muted-foreground text-xs">{copy.available(line.available, line.unit)}</span>
      {line.tiers.length > 0 && (
        <span className="flex flex-wrap gap-1" aria-label={copy.tiers}>
          {line.tiers.map((tier) => (
            <Badge
              key={tier.min_quantity}
              variant={line.tier_min_quantity === tier.min_quantity ? "default" : "outline"}
              className="text-xs"
            >
              {copy.tier(tier.min_quantity, formatMoney(tier.unit_price ?? "0.00"))}
            </Badge>
          ))}
        </span>
      )}
      {problem !== null && (
        <span className="text-destructive text-xs" role="status" data-testid={`problem-${line.sku_id}`}>
          {problem.reason === "insufficient_stock"
            ? reasons.insufficient_stock(problem.available ?? 0, line.unit)
            : problem.reason === "below_moq"
              ? reasons.below_moq(problem.moq ?? line.moq, line.unit)
              : reasons.product_inactive}
        </span>
      )}
    </>
  );
}

/** The order-level refusals the quote found: the minimum order value (BR-MOV02) and the credit limit (BR-C05). */
function QuoteAlerts({ quote }: { quote: OrderQuote }) {
  return (
    <>
      {!quote.mov.satisfied && quote.mov.amount !== null && (
        <Alert>
          <AlertDescription>
            {copy.movShort(formatMoney(quote.mov.amount), formatMoney(quote.items_total))}
          </AlertDescription>
        </Alert>
      )}
      {quote.credit !== null && (
        <Alert variant="destructive">
          <AlertDescription>
            {copy.creditShort(
              formatMoney(quote.credit.limit),
              formatMoney(quote.credit.exposure),
              formatMoney(quote.credit.order_total),
            )}
          </AlertDescription>
        </Alert>
      )}
    </>
  );
}
