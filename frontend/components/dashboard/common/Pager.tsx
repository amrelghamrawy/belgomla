"use client";

import { ChevronLeftIcon, ChevronRightIcon } from "lucide-react";

import { Button } from "@/components/ui/button";
import type { PaginationMeta } from "@/lib/api/types";
import { ar } from "@/lib/copy/ar";

const copy = ar.dashboard.list;

/** Previous / next with "page x of y" and the total. Hidden when everything fits on one page. */
export function Pager({ meta, onPage }: { meta: PaginationMeta | undefined; onPage: (page: number) => void }) {
  if (meta === undefined) {
    return null;
  }

  return (
    <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
      <span className="text-muted-foreground">{copy.total(meta.total)}</span>
      {meta.last_page > 1 && (
        <div className="flex items-center gap-2">
          <Button
            variant="outline"
            size="sm"
            disabled={meta.current_page <= 1}
            onClick={() => onPage(meta.current_page - 1)}
          >
            <ChevronLeftIcon className="rtl:rotate-180" />
            {copy.previous}
          </Button>
          <span>{copy.pageOf(meta.current_page, meta.last_page)}</span>
          <Button
            variant="outline"
            size="sm"
            disabled={meta.current_page >= meta.last_page}
            onClick={() => onPage(meta.current_page + 1)}
          >
            {copy.next}
            <ChevronRightIcon className="rtl:rotate-180" />
          </Button>
        </div>
      )}
    </div>
  );
}
