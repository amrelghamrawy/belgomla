"use client";

import { TrashIcon } from "lucide-react";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { NativeSelect } from "@/components/ui/native-select";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import type { OrderQuote, OrderQuoteLine } from "@/lib/api/types";
import { ar } from "@/lib/copy/ar";
import { type DraftLine, quotedLine } from "@/lib/dashboard/new-order";
import { type DiscountDraft, hasDiscount, NO_DISCOUNT } from "@/lib/dashboard/price-math";
import { formatMoney } from "@/lib/format/money";

import { DiscountInput } from "./DiscountInput";

const copy = ar.dashboard.orders.newOrderForm;

/**
 * The lines with the quote's price, tier, stock and problem next to each (BR-PR04, BR-MOQ01, BR-INV01): the new-order
 * form (BR-O11) and the edit of an order's lines (BR-O12). `marks` labels a line (a new or changed one in an edit).
 */
export function OrderLinesTable({
  lines,
  quote,
  discounts,
  priced,
  marks,
  onDiscount,
  onUnit,
  onQuantity,
  onRemove,
}: {
  lines: DraftLine[];
  quote: OrderQuote | undefined;
  /** The line discounts, or null when the user may not discount (D85). */
  discounts: Record<number, DiscountDraft> | null;
  /** The prices after the discounts, by line, while any discount is typed. */
  priced: ({ unit_price: string; line_total: string } | null)[] | null;
  /** A label per SKU shown next to its name (an added or changed line of an edit, BR-O12). */
  marks?: Record<number, string>;
  onDiscount?: (skuUnitId: number, draft: DiscountDraft) => void;
  /** D91: moves a line to another unit of its SKU (shown when the line lists more than one). */
  onUnit?: (from: number, to: number) => void;
  onQuantity: (skuUnitId: number, quantity: number) => void;
  onRemove: (skuUnitId: number) => void;
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
            const quoted = quotedLine(quote, line.sku_unit_id);
            const after = priced?.[index];
            const draft = discounts?.[line.sku_unit_id] ?? NO_DISCOUNT;
            const shown = hasDiscount(draft) && after ? after : null;

            return (
              <TableRow key={line.sku_unit_id} data-testid={`line-${line.sku_unit_id}`}>
                <TableCell className="min-w-44 align-top">
                  <div className="flex flex-col gap-1">
                    <span className="font-medium">
                      {line.name}
                      {marks?.[line.sku_unit_id] !== undefined && (
                        <Badge variant="secondary" className="ms-2 text-xs">
                          {marks[line.sku_unit_id]}
                        </Badge>
                      )}
                      {quoted?.is_bundle === true && (
                        <Badge variant="outline" className="ms-2 text-xs">
                          {ar.dashboard.bundles.badge}
                        </Badge>
                      )}
                    </span>
                    <bdi dir="ltr" className="text-muted-foreground text-start text-xs">
                      {line.code}
                    </bdi>
                    {onUnit !== undefined && line.units !== undefined && line.units.length > 1 && (
                      <NativeSelect
                        aria-label={copy.unitOf(line.name)}
                        className="w-36"
                        value={line.sku_unit_id}
                        onChange={(event) => onUnit(line.sku_unit_id, Number(event.target.value))}
                      >
                        {line.units.map((unit) => (
                          <option key={unit.id} value={unit.id}>
                            {unit.name}
                          </option>
                        ))}
                      </NativeSelect>
                    )}
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
                    onChange={(event) => onQuantity(line.sku_unit_id, Number(event.target.value))}
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
                      id={`line-discount-${line.sku_unit_id}`}
                      label={line.name}
                      draft={draft}
                      invalid={hasDiscount(draft) && priced !== null && after === null}
                      onChange={(next) => onDiscount?.(line.sku_unit_id, next)}
                    />
                  </TableCell>
                )}
                <TableCell className="align-top">
                  <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    aria-label={`${copy.remove}: ${line.name}`}
                    onClick={() => onRemove(line.sku_unit_id)}
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
      {ar.dashboard.orders.priceSources[line.price_source] !== undefined && (
        <span className="text-primary text-xs" data-testid={`price-source-${line.sku_unit_id}`}>
          {ar.dashboard.orders.priceSources[line.price_source]}
        </span>
      )}
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
        <span className="text-destructive text-xs" role="status" data-testid={`problem-${line.sku_unit_id}`}>
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
