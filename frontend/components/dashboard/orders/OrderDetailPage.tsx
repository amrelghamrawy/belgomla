"use client";

import { ChevronRightIcon } from "lucide-react";
import Link from "next/link";
import { useState } from "react";
import { toast } from "sonner";

import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import type { Collection, DashboardOrderDetail } from "@/lib/api/types";
import { ar } from "@/lib/copy/ar";
import { allowsAddressEdit } from "@/lib/dashboard/order-status";
import { balanceOf, useOrder, useVoidCollection } from "@/lib/dashboard/orders";
import { formatDateTime } from "@/lib/format/date";
import { formatMoney } from "@/lib/format/money";
import { formatPhone } from "@/lib/format/phone";
import { useRouteParam } from "@/lib/store/route";

import { ReasonDialog } from "../common/ReasonDialog";
import { ErrorBlock, LoadingBlock, PageHeader } from "../common/States";
import { DeliveryAddressDialog } from "./DeliveryAddressDialog";
import { OrderActions } from "./OrderActions";
import { CollectionStatusBadge, OrderStatusBadge, StaleBadge } from "./OrderBadges";

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

/** One order: actions, items, totals and collections, customer and delivery, and the status timeline. */
export function OrderDetail({ order }: { order: DashboardOrderDetail }) {
  const [editingAddress, setEditingAddress] = useState(false);
  const address = order.delivery.address;

  return (
    <>
      <Button asChild variant="link" className="self-start px-0">
        <Link href="/dashboard/orders">
          <ChevronRightIcon className="rtl:rotate-180" />
          {copy.back}
        </Link>
      </Button>
      <PageHeader
        title={copy.orderTitle(order.number)}
        description={
          <span className="flex flex-wrap items-center gap-2">
            <OrderStatusBadge status={order.status} />
            <CollectionStatusBadge status={order.collection_status} />
            {order.is_stale && <StaleBadge />}
            <span>{formatDateTime(order.placed_at)}</span>
          </span>
        }
      />
      <OrderActions order={order} />
      {order.status === "cancelled" && order.cancel_reason !== null && (
        <Alert>
          <AlertDescription>
            {copy.cancelledReason(order.cancel_reason, copy.by[order.cancelled_by ?? "tenant_user"] ?? "")}
          </AlertDescription>
        </Alert>
      )}
      <div className="grid gap-4 lg:grid-cols-3">
        <div className="flex flex-col gap-4 lg:col-span-2">
          <Card>
            <CardHeader>
              <CardTitle>{copy.items}</CardTitle>
            </CardHeader>
            <CardContent className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>{copy.product}</TableHead>
                    <TableHead>{copy.quantity}</TableHead>
                    <TableHead>{copy.unitPrice}</TableHead>
                    <TableHead>{copy.lineTotal}</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {order.items.map((item) => (
                    <TableRow key={item.sku_id}>
                      <TableCell>
                        <span className="font-medium">{item.product_name}</span>{" "}
                        <bdi className="text-muted-foreground text-xs">{item.sku_code}</bdi>
                      </TableCell>
                      <TableCell className="whitespace-nowrap">
                        {ar.orders.quantityOf(item.quantity, item.unit)}
                      </TableCell>
                      <TableCell className="whitespace-nowrap">{formatMoney(item.unit_price)}</TableCell>
                      <TableCell className="whitespace-nowrap">{formatMoney(item.line_total)}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
              <dl className="mt-4 grid grid-cols-2 gap-1 text-sm sm:max-w-xs">
                <dt className="text-muted-foreground">{copy.itemsTotal}</dt>
                <dd>{formatMoney(order.items_total)}</dd>
                <dt className="text-muted-foreground">{copy.orderTotal}</dt>
                <dd className="font-bold">{formatMoney(order.order_total)}</dd>
                <dt className="text-muted-foreground">{copy.collected}</dt>
                <dd>{formatMoney(order.collected_amount)}</dd>
                <dt className="text-muted-foreground">{copy.balance}</dt>
                <dd className="font-bold" data-testid="order-balance">
                  {formatMoney(balanceOf(order))}
                </dd>
              </dl>
            </CardContent>
          </Card>
          <CollectionsCard collections={order.collections} />
        </div>
        <div className="flex flex-col gap-4">
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
            <CardHeader className="flex-row items-center justify-between">
              <CardTitle>{copy.delivery}</CardTitle>
              {allowsAddressEdit(order.status) ? (
                <Button variant="outline" size="sm" onClick={() => setEditingAddress(true)}>
                  {copy.editAddress}
                </Button>
              ) : null}
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
        </div>
      </div>
      {editingAddress && <DeliveryAddressDialog order={order} onClose={() => setEditingAddress(false)} />}
    </>
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
          {formatMoney(collection.amount)} · {collection.method_label}
        </span>
        <span className="text-muted-foreground text-xs">{formatDateTime(collection.collected_at)}</span>
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
