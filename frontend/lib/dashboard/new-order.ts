"use client";

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useEffect, useState } from "react";

import { apiFetch } from "@/lib/api/client";
import { useIdempotencyKey } from "@/lib/api/idempotency";
import { queryKeys } from "@/lib/api/keys";
import type {
  DashboardOrderDetail,
  DashboardProduct,
  MerchantOrderRequest,
  OrderQuote,
  OrderQuoteLine,
  OrderQuoteRequest,
} from "@/lib/api/types";

import { type DiscountDraft, discountBody, discountTotals } from "./price-math";

// BR-O11, D84: the merchant places an order for a customer (POST /api/dashboard/orders), quoting it first
// (POST /api/dashboard/orders/quote) so the form shows the checkout's prices, stock and problems as it is filled.

/** A unit the line's SKU is sold in (V2 Task 13, D91), for the line's unit select. */
export type UnitOption = { id: number; name: string; moq: number };

/**
 * A line of the form, before the quote prices it: one SKU unit (D91; the same product may come in two units) and a
 * quantity in that unit. `units` lists the SKU's units when the line may still switch between them.
 */
export type DraftLine = { sku_unit_id: number; quantity: number; name: string; code: string; units?: UnitOption[] };

/** A picked product as a new line: its default unit at that unit's MOQ; null when it has no unit to sell in. */
export function lineFromProduct(product: DashboardProduct): DraftLine | null {
  const units = product.sku?.units ?? [];
  const chosen = units.find((unit) => unit.is_default) ?? units[0];

  if (product.sku === null || chosen === undefined) {
    return null;
  }

  return {
    sku_unit_id: chosen.id,
    quantity: chosen.moq,
    name: product.name,
    code: product.sku.code,
    units: units.map((unit) => ({ id: unit.id, name: unit.unit?.name ?? "", moq: unit.moq })),
  };
}

/** Moves a line to another unit of its SKU at that unit's MOQ; nothing when the SKU is already there in that unit. */
export function setUnit<T extends DraftLine>(lines: T[], from: number, to: number): T[] {
  if (lines.some((line) => line.sku_unit_id === to)) {
    return lines;
  }

  return lines.map((line) => {
    const unit = line.units?.find((option) => option.id === to);

    return line.sku_unit_id === from && unit !== undefined ? { ...line, sku_unit_id: to, quantity: unit.moq } : line;
  });
}

/** How long the form waits after a change before asking for a new quote. */
export const QUOTE_DELAY_MS = 300;

/** Adds a SKU unit to the lines, or does nothing when it is already there. */
export function addLine<T extends DraftLine>(lines: T[], line: T): T[] {
  return lines.some((current) => current.sku_unit_id === line.sku_unit_id) ? lines : [...lines, line];
}

/** Sets a line's quantity; anything that is not a whole number of at least 1 (an emptied field) becomes 0. */
export function setQuantity<T extends DraftLine>(lines: T[], skuUnitId: number, quantity: number): T[] {
  const clean = Number.isInteger(quantity) && quantity >= 1 ? quantity : 0;

  return lines.map((line) => (line.sku_unit_id === skuUnitId ? { ...line, quantity: clean } : line));
}

export function removeLine<T extends DraftLine>(lines: T[], skuUnitId: number): T[] {
  return lines.filter((line) => line.sku_unit_id !== skuUnitId);
}

/** The quote body for the form, or null while there is nothing (valid) to quote: no customer, no line, an empty quantity. */
export function quoteBody(customerId: number | null, lines: DraftLine[]) {
  if (customerId === null || lines.length === 0 || lines.some((line) => line.quantity < 1)) {
    return null;
  }

  return { customer_id: customerId, lines: lines.map(({ sku_unit_id, quantity }) => ({ sku_unit_id, quantity })) };
}

/**
 * Whether the quoted order would be accepted: no line problem, within the credit limit, and the minimum order value
 * reached or waived (D84: only by a user who may, and never the MOQ, stock or credit).
 */
export function quotePasses(quote: OrderQuote, overrideMov: boolean): boolean {
  return (
    quote.lines.every((line) => line.problem === null) && quote.credit === null && (quote.mov.satisfied || overrideMov)
  );
}

/** The quoted line of a SKU unit, if the quote has it. */
export function quotedLine(quote: OrderQuote | undefined, skuUnitId: number): OrderQuoteLine | undefined {
  return quote?.lines.find((line) => line.sku_unit_id === skuUnitId);
}

/** The value after a pause in changes, so the quote is not asked for on every keystroke. */
export function useSettled<T>(value: T): T {
  const [settled, setSettled] = useState(value);

  useEffect(() => {
    const timer = setTimeout(() => setSettled(value), QUOTE_DELAY_MS);

    return () => clearTimeout(timer);
  }, [value]);

  return settled;
}

/** The live quote of the form (a new order, or an edit with `order`, BR-O12); the previous one stays on screen while the next loads. */
export function useOrderQuote(body: OrderQuoteRequest | null) {
  return useQuery({
    queryKey: queryKeys.dashboardOrderQuote(body ?? {}),
    queryFn: () => apiFetch<OrderQuote>("/api/dashboard/orders/quote", { method: "POST", body }),
    enabled: body !== null,
    placeholderData: keepPreviousData,
    retry: false,
  });
}

/** Places the order (retry-safe, D55) and refreshes everything a new order moves: lists, stock, the customer. */
export function usePlaceOrder() {
  const queryClient = useQueryClient();
  const idempotency = useIdempotencyKey("merchant-order");

  return useMutation({
    mutationFn: (body: MerchantOrderRequest) =>
      apiFetch<DashboardOrderDetail>("/api/dashboard/orders", {
        method: "POST",
        body,
        headers: { "Idempotency-Key": idempotency.keyFor(body) },
      }),
    onSuccess: (order) => {
      idempotency.reset();
      queryClient.setQueryData(queryKeys.dashboardOrder(order.number), order);
      for (const key of [
        queryKeys.dashboardOrdersLists,
        queryKeys.dashboardOverview,
        ["dashboard", "inventory"],
        ["dashboard", "customers"],
        ["dashboard", "reports"],
      ]) {
        void queryClient.invalidateQueries({ queryKey: key });
      }
    },
  });
}

/**
 * D85: the order's lines for the API with each line's discount (only lines that have one carry `discount`). The
 * discounts apply after the order is placed at list prices, so the quote's checks stay those of the list prices.
 */
export function orderLinesBody(lines: DraftLine[], discounts: Record<number, DiscountDraft>) {
  return lines.map(({ sku_unit_id, quantity }) => {
    const discount = discountBody(discounts[sku_unit_id]);

    return discount === null ? { sku_unit_id, quantity } : { sku_unit_id, quantity, discount };
  });
}

/** D85: the totals after the form's discounts, priced from the quote (each line's quoted price is its list price). */
export function discountedQuote(
  quote: OrderQuote | undefined,
  lines: DraftLine[],
  discounts: Record<number, DiscountDraft>,
  order: DiscountDraft,
) {
  const quoted = lines.map((line) => quotedLine(quote, line.sku_unit_id));

  if (quote === undefined || quoted.some((line) => line === undefined)) {
    return null;
  }

  return discountTotals(
    lines.map((line, index) => ({
      list_unit_price: quoted[index]?.unit_price ?? "0.00",
      quantity: line.quantity,
      discount: discounts[line.sku_unit_id],
    })),
    order,
  );
}
