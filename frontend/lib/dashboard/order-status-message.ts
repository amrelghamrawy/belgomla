// BR-N03: the merchant's wording of the WhatsApp order status messages. Mirrors App\Support\OrderStatusMessage.

import type { DashboardSettings } from "@/lib/api/types";

export type NotifiableStatus = DashboardSettings["order_notify_statuses"][number];

/** The statuses a store can notify, in the platform's order, then `discount` (the merchant changed the discounts, D85). */
export const NOTIFIABLE_STATUSES: readonly NotifiableStatus[] = [
  "confirmed",
  "preparing",
  "out_for_delivery",
  "delivered",
  "cancelled",
  "discount",
];

export const ORDER_MESSAGE_MAX_LENGTH = 500;

export const ORDER_MESSAGE_PLACEHOLDERS = {
  customer: "{اسم_العميل}",
  store: "{اسم_المتجر}",
  number: "{رقم_الطلب}",
  status: "{حالة_الطلب}",
  total: "{الإجمالي}",
  reason: "{سبب_الإلغاء}",
} as const;

export type OrderMessagePlaceholder = keyof typeof ORDER_MESSAGE_PLACEHOLDERS;

/** The placeholders a status can fill: the cancel reason belongs to the cancelled text only. */
export function placeholdersFor(status: NotifiableStatus): OrderMessagePlaceholder[] {
  const all = Object.keys(ORDER_MESSAGE_PLACEHOLDERS) as OrderMessagePlaceholder[];

  return status === "cancelled" ? all : all.filter((key) => key !== "reason");
}

/** Every `{...}` the status cannot fill, once each, in order of appearance. */
export function unknownOrderPlaceholders(template: string, status: NotifiableStatus): string[] {
  const allowed: string[] = placeholdersFor(status).map((key) => ORDER_MESSAGE_PLACEHOLDERS[key]);
  const found = template.match(/\{[^{}]*\}/g) ?? [];

  return [...new Set(found.filter((token) => !allowed.includes(token)))];
}

export type OrderMessageValues = Record<OrderMessagePlaceholder, string>;

/** The text the customer receives. */
export function renderOrderMessage(template: string, values: OrderMessageValues): string {
  let text = template;

  for (const key of Object.keys(ORDER_MESSAGE_PLACEHOLDERS) as OrderMessagePlaceholder[]) {
    text = text.replaceAll(ORDER_MESSAGE_PLACEHOLDERS[key], values[key]);
  }

  return text.trim();
}

/** What to send per status: an empty box or the default text sent back keeps the platform default (null). */
export function orderMessagesBody(
  texts: Record<NotifiableStatus, string>,
  defaults: Record<NotifiableStatus, string>,
): Record<NotifiableStatus, string | null> {
  const body = {} as Record<NotifiableStatus, string | null>;

  for (const status of NOTIFIABLE_STATUSES) {
    const trimmed = texts[status].trim();
    body[status] = trimmed === "" || trimmed === defaults[status].trim() ? null : trimmed;
  }

  return body;
}
