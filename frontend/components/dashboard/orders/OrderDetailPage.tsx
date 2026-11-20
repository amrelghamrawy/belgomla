"use client";

import { ChevronRightIcon } from "lucide-react";
import Link from "next/link";
import { useState } from "react";
import { toast } from "sonner";

import { BundleComponentsList } from "@/components/shared/BundleComponentsList";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardAction, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import type {
  Collection,
  DashboardOrderDetail,
  DashboardOrderItemChange,
  OrderItem,
  OrderReturn,
} from "@/lib/api/types";
import { ar } from "@/lib/copy/ar";
import { allowsAddressEdit } from "@/lib/dashboard/order-status";
import { balanceOf, useOrder, useVoidCollection } from "@/lib/dashboard/orders";
import { useVoidReturn } from "@/lib/dashboard/returns";
import { changedLineIds, groupOrderLines, variantOf } from "@/lib/store/order-lines";
import { formatDateTime } from "@/lib/format/date";
import { formatMoney } from "@/lib/format/money";
import { formatPhone } from "@/lib/format/phone";
import { useRouteParam } from "@/lib/store/route";

import { ReasonDialog } from "../common/ReasonDialog";
import { ErrorBlock, LoadingBlock, PageHeader } from "../common/States";
import { DeliveryAddressDialog } from "./DeliveryAddressDialog";
import { OrderActions } from "./OrderActions";
import {
  CollectionStatusBadge,
  ExpiryBadge,
  OrderStatusBadge,
  PlacedByMerchantBadge,
  PreorderBadge,
  StaleBadge,
} from "./OrderBadges";

const copy = ar.dashboard.orders;

export function OrderDetailPage() {
  const number = Number(useRouteParam("number"));
  const order = useOrder(number);

  if (order.isError) {
    return <ErrorBlock error={order.error} onRetry={() => void order.refetch()} />;
  }

  if (order.data === undefined) {
    return <LoadingBlock rows={8} />;
  }

  return <OrderDetail order={order.data} />;
}

/**
 * One order: actions, items (removed lines struck through, changed and short ones marked, BR-O12, BR-O13), totals,
 * line changes, returns and collections, customer and delivery, and the status timeline.
 */
export function OrderDetail({ order }: { order: DashboardOrderDetail }) {
  const [editingAddress, setEditingAddress] = useState(false);
  const address = order.delivery.address;
  const changed = changedLineIds(order);

  return (
    <>
      <Button asChild variant="link" className="self-start px-0">
        <Link href="/dashboard/orders">
          <ChevronRightIcon className="rtl:rotate-180" />
          {copy.back}
        </Link>
      </Button>
      <PageHeader
        title={order.kind === "preorder" ? copy.preorderTitle(order.number) : copy.orderTitle(order.number)}
        description={
          <span className="flex flex-wrap items-center gap-2">
            {order.preorder_state === null ? (
              <OrderStatusBadge status={order.status} />
            ) : (
              <PreorderBadge state={order.preorder_state} />
            )}
            {order.kind === "order" && <CollectionStatusBadge status={order.collection_status} />}
            {order.is_stale && <StaleBadge />}
            {order.expires_at !== null && <ExpiryBadge expiresAt={order.expires_at} />}
            {order.placed_by === "tenant_user" && <PlacedByMerchantBadge />}
            <span>{formatDateTime(order.placed_at)}</span>
          </span>
        }
      />
      <OrderActions order={order} />
      {(order.conversions.length > 0 || order.converted_from !== null) && (
        <p className="flex flex-wrap items-center gap-2 text-sm" data-testid="order-conversions">
          {order.converted_from !== null ? (
            <Link href={`/dashboard/orders/${order.converted_from}`} className="text-primary underline">
              {copy.convertedFrom(order.converted_from)}
            </Link>
          ) : (
            <>
              <span className="font-medium">{copy.convertedTo}:</span>
              {order.conversions.map((conversion) => (
                <Link
                  key={conversion.number}
                  href={`/dashboard/orders/${conversion.number}`}
                  className="text-primary underline"
                >
                  <bdi className="text-primary font-bold tabular-nums">#{conversion.number}</bdi>
                </Link>
              ))}
            </>
          )}
        </p>
      )}
      {order.status === "cancelled" && order.cancel_reason !== null && (
        <Alert>
          <AlertDescription>
            {copy.cancelledReason(order.cancel_reason, copy.by[order.cancelled_by ?? "tenant_user"] ?? "")}
          </AlertDescription>
        </Alert>
      )}
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <div className="flex min-w-0 flex-col gap-4 lg:col-span-2">
          <Card>
            <CardHeader>
              <CardTitle>{copy.items}</CardTitle>
            </CardHeader>
            <CardContent className="overflow-x-auto">
              <Table stack>
                <TableHeader>
                  <TableRow>
                    <TableHead>{copy.product}</TableHead>
                    <TableHead>{copy.quantity}</TableHead>
                    <TableHead>{copy.unitPrice}</TableHead>
                    <TableHead>{copy.lineTotal}</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {groupOrderLines(order.items).map(({ item, components }) => (
                    <TableRow
                      key={item.id}
                      data-testid={`order-line-${item.id}`}
                      className={item.removed_at === null ? undefined : "text-muted-foreground line-through"}
                    >
                      <TableCell className="align-top">
                        <span className="font-medium">{item.product_name}</span>{" "}
                        <bdi className="text-muted-foreground text-xs">{item.sku_code}</bdi>
                        {item.is_bundle && (
                          <Badge variant="outline" className="ms-2 text-xs">
                            {ar.dashboard.bundles.badge}
                          </Badge>
                        )}
                        {variantOf(item) !== null && (
                          <span className="text-muted-foreground block text-xs" data-testid={`variant-${item.id}`}>
                            {variantOf(item)}
                          </span>
                        )}
                        <LineBadges item={item} changed={changed.has(item.id)} />
                        <BundleComponentsList bundle={item} components={components} />
                      </TableCell>
                      <TableCell className="whitespace-nowrap">
                        {ar.orders.quantityOf(item.quantity, item.unit)}
                        {item.converted_quantity > 0 && (
                          <span className="text-primary block text-xs" data-testid={`converted-${item.id}`}>
                            {copy.convertedOf(item.converted_quantity, item.quantity)}
                          </span>
                        )}
                        {item.delivered_quantity !== null && item.delivered_quantity < item.quantity && (
                          <span className="text-destructive block text-xs" data-testid={`delivered-${item.id}`}>
                            {copy.deliveredOf(item.delivered_quantity, item.quantity)}
                          </span>
                        )}
                      </TableCell>
                      <TableCell className="whitespace-nowrap">
                        {item.manual_discount !== null && (
                          <s className="text-muted-foreground me-1 text-xs" aria-label={copy.listPrice}>
                            {formatMoney(item.list_unit_price)}
                          </s>
                        )}
                        {formatMoney(item.unit_price)}
                        {copy.priceSources[item.price_source] !== undefined && (
                          <span className="text-primary block text-xs" data-testid={`price-source-${item.id}`}>
                            {copy.priceSources[item.price_source]}
                          </span>
                        )}
                      </TableCell>
                      <TableCell className="whitespace-nowrap">{formatMoney(item.line_total)}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
              <dl className="mt-4 grid grid-cols-2 gap-1 text-sm sm:max-w-xs">
                <dt className="text-muted-foreground">{copy.itemsTotal}</dt>
                <dd>{formatMoney(order.items_total)}</dd>
                {order.discount !== null && (
                  <>
                    <dt className="text-muted-foreground">{copy.discountTotal}</dt>
                    <dd data-testid="order-discount">
                      −{formatMoney(order.discount.amount)}{" "}
                      <span className="text-muted-foreground text-xs">{order.discount.reason}</span>
                    </dd>
                  </>
                )}
                <dt className="text-muted-foreground">{copy.orderTotal}</dt>
                <dd className="font-bold">{formatMoney(order.order_total)}</dd>
                {order.returned_total !== "0.00" && (
                  <>
                    <dt className="text-muted-foreground">{copy.returnedTotal}</dt>
                    <dd data-testid="order-returned">−{formatMoney(order.returned_total)}</dd>
                    <dt className="text-muted-foreground">{copy.netTotal}</dt>
                    <dd className="font-bold">{formatMoney(order.net_total)}</dd>
                  </>
                )}
                <dt className="text-muted-foreground">{copy.collected}</dt>
                <dd>{formatMoney(order.collected_amount)}</dd>
                <dt className="text-muted-foreground">{copy.balance}</dt>
                <dd className="font-bold" data-testid="order-balance">
                  {formatMoney(balanceOf(order))}
                </dd>
              </dl>
            </CardContent>
          </Card>
          <ChangesCard changes={order.changes} />
          <ReturnsCard returns={order.returns} />
          <CollectionsCard collections={order.collections} />
        </div>
        <div className="flex min-w-0 flex-col gap-4">
          <Card>
            <CardHeader>
              <CardTitle>{copy.customer}</CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-1 text-sm">
              {order.customer ? (
                <Link href={`/dashboard/customers/${order.customer.id}`} className="font-medium hover:underline">
                  {order.customer.name}
                </Link>
              ) : null}
              {order.customer_note !== null && (
                <p className="text-muted-foreground">
                  {copy.customerNote}: {order.customer_note}
                </p>
              )}
            </CardContent>
          </Card>
          <Card>
            <CardHeader>
              <CardTitle>{copy.delivery}</CardTitle>
              <CardAction>
                {allowsAddressEdit(order.status) ? (
                  <Button variant="outline" size="sm" onClick={() => setEditingAddress(true)}>
                    {copy.editAddress}
                  </Button>
                ) : null}
              </CardAction>
            </CardHeader>
            <CardContent className="flex flex-col gap-1 text-sm" data-testid="delivery">
              <span className="font-medium">{order.delivery.name}</span>
              <bdi>{formatPhone(order.delivery.phone)}</bdi>
              <span>{address.label}</span>
              <span>{[address.address_line, address.city, address.governorate].join("، ")}</span>
              {address.landmark !== null && <span className="text-muted-foreground">{address.landmark}</span>}
              {!allowsAddressEdit(order.status) && order.status !== "cancelled" && (
                <p className="text-muted-foreground text-xs">{copy.addressLocked}</p>
              )}
            </CardContent>
          </Card>
          <Card>
            <CardHeader>
              <CardTitle>{copy.timeline}</CardTitle>
            </CardHeader>
            <CardContent>
              <ol className="flex flex-col gap-3 border-s ps-4 text-sm">
                {order.history.map((entry, index) => (
                  <li key={`${entry.at}-${index}`} className="flex flex-col">
                    <span className="font-medium">
                      {entry.from === null ? copy.placed : ar.orders.statuses[entry.to]}
                    </span>
                    <span className="text-muted-foreground text-xs">
                      {formatDateTime(entry.at)} · {copy.by[entry.by] ?? entry.by}
                    </span>
                    {entry.note !== null && <span className="text-xs">{entry.note}</span>}
                  </li>
                ))}
              </ol>
            </CardContent>
          </Card>
          {order.messages.length > 0 && (
            <Card>
              <CardHeader>
                <CardTitle>{copy.messages}</CardTitle>
              </CardHeader>
              <CardContent>
                <ol className="flex flex-col gap-3 border-s ps-4 text-sm" data-testid="order-messages">
                  {order.messages.map((message, index) => (
                    <li key={`${message.sent_at}-${index}`} className="flex flex-col">
                      <span className="font-medium">
                        {message.purpose_label} · {copy.messageChannels[message.channel] ?? message.channel}
                      </span>
                      <span className="text-muted-foreground text-xs">
                        {formatDateTime(message.sent_at)} · {copy.messageStatuses[message.status] ?? message.status}
                      </span>
                    </li>
                  ))}
                </ol>
              </CardContent>
            </Card>
          )}
        </div>
      </div>
      {editingAddress && <DeliveryAddressDialog order={order} onClose={() => setEditingAddress(false)} />}
    </>
  );
}

/** BR-O12, BR-O13: a removed line, or one added or changed after the order was placed. */
function LineBadges({ item, changed }: { item: OrderItem; changed: boolean }) {
  if (item.removed_at !== null) {
    return (
      <Badge variant="outline" className="ms-2 text-xs no-underline">
        {copy.removedBadge}
      </Badge>
    );
  }

  return changed ? (
    <Badge variant="secondary" className="ms-2 text-xs">
      {copy.changedBadge}
    </Badge>
  ) : null;
}

/** BR-O12, BR-O13: the line changes after placement, oldest first; shown only when there are some. */
function ChangesCard({ changes }: { changes: DashboardOrderItemChange[] }) {
  const labels = copy.changes;

  if (changes.length === 0) {
    return null;
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>{labels.title}</CardTitle>
      </CardHeader>
      <CardContent>
        <ol className="flex flex-col gap-2 border-s ps-4 text-sm" data-testid="order-changes">
          {changes.map((change, index) => {
            const name = change.product_name ?? labels.unknownLine;

            return (
              <li key={`${change.at}-${index}`} className="flex flex-col">
                <span>
                  {change.change === "added"
                    ? labels.added(name, change.new_quantity)
                    : change.change === "removed"
                      ? labels.removed(name, change.old_quantity)
                      : change.change === "quantity"
                        ? labels.quantity(name, change.old_quantity, change.new_quantity)
                        : change.change === "converted"
                          ? labels.converted(name, change.old_quantity, change.new_quantity)
                          : labels.delivered_short(name, change.old_quantity, change.new_quantity)}
                </span>
                <span className="text-muted-foreground text-xs">{formatDateTime(change.at)}</span>
              </li>
            );
          })}
        </ol>
      </CardContent>
    </Card>
  );
}

/** BR-R06: every return of the order, voided ones struck through, each live one voidable with a reason. */
function ReturnsCard({ returns }: { returns: OrderReturn[] }) {
  const labels = ar.dashboard.returns;
  const voidReturn = useVoidReturn();

  if (returns.length === 0) {
    return null;
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>{labels.sectionTitle}</CardTitle>
      </CardHeader>
      <CardContent>
        <ul className="flex flex-col divide-y" data-testid="order-returns">
          {returns.map((orderReturn) => (
            <li key={orderReturn.id} className="flex flex-wrap items-start justify-between gap-2 py-2 text-sm">
              <span className="flex min-w-0 flex-col">
                <span className={orderReturn.is_voided ? "text-muted-foreground line-through" : "font-medium"}>
                  {labels.returnNumber(orderReturn.number)} · {formatMoney(orderReturn.total)}
                </span>
                <span className="text-muted-foreground text-xs">
                  {formatDateTime(orderReturn.returned_at)} · {orderReturn.reason}
                </span>
                {orderReturn.items.map((item) => (
                  <span key={item.id} className="text-xs">
                    {item.product_name} × {ar.orders.quantityOf(item.quantity, item.unit)}
                  </span>
                ))}
                {orderReturn.refund_amount !== "0.00" && (
                  <span className="text-xs">{labels.refunded(formatMoney(orderReturn.refund_amount))}</span>
                )}
                {orderReturn.void_reason !== null && (
                  <span className="text-xs">{labels.voidReason(orderReturn.void_reason)}</span>
                )}
              </span>
              {orderReturn.is_voided ? (
                <Badge variant="secondary">{labels.voidedBadge}</Badge>
              ) : (
                <ReasonDialog
                  trigger={(open) => (
                    <Button variant="ghost" size="sm" className="text-destructive" onClick={open}>
                      {labels.void}
                    </Button>
                  )}
                  title={labels.voidTitle}
                  description={labels.voidBody}
                  confirmLabel={labels.void}
                  destructive
                  onSubmit={async (reason) => {
                    await voidReturn.mutateAsync({ id: orderReturn.id, reason });
                    toast.success(labels.voided);
                  }}
                />
              )}
            </li>
          ))}
        </ul>
      </CardContent>
    </Card>
  );
}

function CollectionsCard({ collections }: { collections: Collection[] }) {
  const labels = ar.dashboard.collections;

  return (
    <Card>
      <CardHeader>
        <CardTitle>{labels.title}</CardTitle>
      </CardHeader>
      <CardContent>
        {collections.length === 0 ? (
          <p className="text-muted-foreground text-sm">{labels.none}</p>
        ) : (
          <ul className="flex flex-col divide-y" data-testid="order-collections">
            {collections.map((collection) => (
              <CollectionLine key={collection.id} collection={collection} />
            ))}
          </ul>
        )}
      </CardContent>
    </Card>
  );
}

export function CollectionLine({ collection }: { collection: Collection }) {
  const labels = ar.dashboard.collections;
  const voidCollection = useVoidCollection();

  return (
    <li className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
      <span className="flex flex-col">
        <span className={collection.is_voided ? "text-muted-foreground line-through" : "font-medium"}>
          {collection.kind === "refund" ? "−" : ""}
          {formatMoney(collection.amount)} · {collection.method_label}
          {collection.kind === "refund" && (
            <Badge variant="outline" className="ms-2">
              {labels.refundBadge}
            </Badge>
          )}
        </span>
        <span className="text-muted-foreground text-xs">{formatDateTime(collection.collected_at)}</span>
        {collection.reference !== null && (
          <span className="text-xs">{ar.dashboard.collections.referenceOf(collection.reference)}</span>
        )}
        {collection.note !== null && <span className="text-xs">{collection.note}</span>}
        {collection.void_reason !== null && (
          <span className="text-xs">{labels.voidReason(collection.void_reason)}</span>
        )}
      </span>
      {collection.is_voided ? (
        <Badge variant="secondary">{labels.voidedBadge}</Badge>
      ) : (
        <ReasonDialog
          trigger={(open) => (
            <Button variant="ghost" size="sm" className="text-destructive" onClick={open}>
              {labels.void}
            </Button>
          )}
          title={labels.voidTitle}
          description={labels.voidBody}
          confirmLabel={labels.void}
          destructive
          onSubmit={async (reason) => {
            await voidCollection.mutateAsync({ id: collection.id, reason });
            toast.success(labels.voided);
          }}
        />
      )}
    </li>
  );
}
