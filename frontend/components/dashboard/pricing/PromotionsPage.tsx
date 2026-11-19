"use client";

import Link from "next/link";
import { useState } from "react";
import { toast } from "sonner";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { NativeSelect } from "@/components/ui/native-select";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import type { Promotion, PromotionStatus } from "@/lib/api/types";
import { ar, messageFor } from "@/lib/copy/ar";
import {
  isCancellable,
  isEditable,
  PROMOTION_LIST_KEYS,
  PROMOTION_STATUSES,
  useCancelPromotion,
  usePromotions,
  useUpdatePromotion,
} from "@/lib/dashboard/promotions";
import { useListQuery } from "@/lib/dashboard/use-list-query";
import { formatDateTime } from "@/lib/format/date";
import { formatPercent } from "@/lib/format/money";

import { Pager } from "../common/Pager";
import { EmptyBlock, ErrorBlock, LoadingBlock, PageHeader } from "../common/States";
import { PromotionDialog } from "./PromotionDialog";

const copy = ar.dashboard.promotions;

const STATUS_VARIANT: Record<PromotionStatus, "default" | "secondary" | "outline"> = {
  draft: "secondary",
  scheduled: "outline",
  active: "default",
  ended: "secondary",
  cancelled: "secondary",
};

/**
 * BR-PR15 (V2 Task 12, D90): the store's promotions, latest start first, filtered by status: edit a draft or one that
 * has not started, schedule a draft, cancel one that has not ended. New promotions start from the products page,
 * whose filter chooses the products (the bulk-pricing filter).
 */
export function PromotionsPage() {
  const [query, setQuery] = useListQuery(PROMOTION_LIST_KEYS);
  const promotions = usePromotions(query);
  const [editing, setEditing] = useState<Promotion | null>(null);

  return (
    <>
      <PageHeader
        title={copy.title}
        description={copy.description}
        actions={
          <Button asChild>
            <Link href="/dashboard/products">{copy.add}</Link>
          </Button>
        }
      />
      <p className="text-muted-foreground text-sm">{copy.addHint}</p>
      <div className="flex items-center gap-2">
        <NativeSelect
          aria-label={copy.status}
          value={query.status ?? ""}
          onChange={(event) => setQuery({ status: event.target.value })}
        >
          <option value="">{ar.dashboard.list.all}</option>
          {PROMOTION_STATUSES.map((status) => (
            <option key={status} value={status}>
              {copy.statuses[status]}
            </option>
          ))}
        </NativeSelect>
      </div>
      {promotions.isError && <ErrorBlock error={promotions.error} onRetry={() => void promotions.refetch()} />}
      {promotions.isPending && <LoadingBlock />}
      {promotions.data?.data.length === 0 && <EmptyBlock>{copy.empty}</EmptyBlock>}
      {promotions.data !== undefined && promotions.data.data.length > 0 && (
        <div className="overflow-x-auto rounded-md border">
          <Table stack>
            <TableHeader>
              <TableRow>
                <TableHead>{copy.name}</TableHead>
                <TableHead>%</TableHead>
                <TableHead>{copy.window}</TableHead>
                <TableHead>{copy.skus}</TableHead>
                <TableHead>{copy.status}</TableHead>
                <TableHead>{ar.dashboard.list.actions}</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {promotions.data.data.map((promotion) => (
                <PromotionRow key={promotion.id} promotion={promotion} onEdit={() => setEditing(promotion)} />
              ))}
            </TableBody>
          </Table>
        </div>
      )}
      <Pager meta={promotions.data?.meta} onPage={(page) => setQuery({ page })} />
      {editing !== null && <PromotionDialog promotion={editing} onClose={() => setEditing(null)} />}
    </>
  );
}

function PromotionRow({ promotion, onEdit }: { promotion: Promotion; onEdit: () => void }) {
  const update = useUpdatePromotion();
  const [cancelling, setCancelling] = useState(false);

  return (
    <TableRow data-testid={`promotion-${promotion.id}`}>
      <TableCell>
        <span className="font-medium">{promotion.name}</span>
        <p className="text-muted-foreground text-xs">
          {promotion.target === "filter" ? copy.targetFilter : copy.targetSkus}
        </p>
      </TableCell>
      <TableCell className="tabular-nums">{formatPercent(promotion.discount_percent)}%</TableCell>
      <TableCell className="text-xs whitespace-nowrap">
        <div>{formatDateTime(promotion.starts_at)}</div>
        <div>{formatDateTime(promotion.ends_at)}</div>
      </TableCell>
      <TableCell>{copy.skusCount(promotion.skus_count)}</TableCell>
      <TableCell>
        <Badge variant={STATUS_VARIANT[promotion.status]}>{copy.statuses[promotion.status]}</Badge>
      </TableCell>
      <TableCell className="flex flex-wrap gap-1">
        {isEditable(promotion.status) && (
          <Button variant="outline" size="sm" onClick={onEdit}>
            {ar.dashboard.edit}
          </Button>
        )}
        {promotion.status === "draft" && (
          <Button
            variant="outline"
            size="sm"
            disabled={update.isPending}
            onClick={() =>
              update.mutate(
                { id: promotion.id, status: "scheduled" },
                { onSuccess: () => toast.success(copy.scheduled), onError: (error) => toast.error(messageFor(error)) },
              )
            }
          >
            {copy.schedule}
          </Button>
        )}
        {isCancellable(promotion.status) && (
          <Button variant="ghost" size="sm" className="text-destructive" onClick={() => setCancelling(true)}>
            {copy.cancel}
          </Button>
        )}
        {cancelling && <CancelDialog promotion={promotion} onClose={() => setCancelling(false)} />}
      </TableCell>
    </TableRow>
  );
}

function CancelDialog({ promotion, onClose }: { promotion: Promotion; onClose: () => void }) {
  const cancel = useCancelPromotion();

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{copy.cancelTitle}</DialogTitle>
          <DialogDescription>
            {promotion.name}. {copy.cancelBody}
          </DialogDescription>
        </DialogHeader>
        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            {copy.keep}
          </Button>
          <Button
            variant="destructive"
            disabled={cancel.isPending}
            onClick={() =>
              cancel.mutate(promotion.id, {
                onSuccess: () => {
                  toast.success(copy.cancelled);
                  onClose();
                },
                onError: (error) => toast.error(messageFor(error)),
              })
            }
          >
            {copy.cancel}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
