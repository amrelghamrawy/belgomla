"use client";

import { InboxIcon } from "lucide-react";
import type { ReactNode } from "react";

import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Empty, EmptyDescription, EmptyHeader, EmptyMedia } from "@/components/ui/empty";
import { Skeleton } from "@/components/ui/skeleton";
import { ar, messageFor } from "@/lib/copy/ar";

/** Placeholder rows while a list or card loads. */
export function LoadingBlock({ rows = 5 }: { rows?: number }) {
  return (
    <div className="flex flex-col gap-2" aria-busy="true" aria-label={ar.loading}>
      {Array.from({ length: rows }, (_, index) => (
        <Skeleton key={index} className="h-10 w-full" />
      ))}
    </div>
  );
}

/** A failed read, with its Arabic message and a retry. */
export function ErrorBlock({ error, onRetry }: { error: unknown; onRetry?: () => void }) {
  return (
    <Alert variant="destructive">
      <AlertDescription className="flex flex-wrap items-center justify-between gap-2">
        <span>{messageFor(error)}</span>
        {onRetry !== undefined && (
          <Button variant="outline" size="sm" onClick={onRetry}>
            {ar.retry}
          </Button>
        )}
      </AlertDescription>
    </Alert>
  );
}

/** Nothing to show: an icon and the reason. */
export function EmptyBlock({ children }: { children?: ReactNode }) {
  return (
    <Empty className="border">
      <EmptyHeader>
        <EmptyMedia variant="icon">
          <InboxIcon />
        </EmptyMedia>
        <EmptyDescription>{children ?? ar.dashboard.list.empty}</EmptyDescription>
      </EmptyHeader>
    </Empty>
  );
}

/** The title row of a page: heading, optional description, actions at the end. */
export function PageHeader({
  title,
  description,
  actions,
}: {
  title: string;
  description?: ReactNode;
  actions?: ReactNode;
}) {
  return (
    <div className="flex flex-wrap items-start justify-between gap-3">
      <div className="flex min-w-0 flex-col gap-1">
        <h1 className="text-xl font-bold md:text-2xl">{title}</h1>
        {description !== undefined && <div className="text-muted-foreground text-sm">{description}</div>}
      </div>
      {actions !== undefined && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
    </div>
  );
}
