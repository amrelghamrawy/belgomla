"use client";

import { DownloadIcon } from "lucide-react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import type { SpreadsheetFormat } from "@/lib/api/types";
import { ar, messageFor } from "@/lib/copy/ar";
import { useExportProducts } from "@/lib/dashboard/catalog-files";
import type { ListQuery } from "@/lib/dashboard/list-query";

import { Hint } from "../common/Hint";

const copy = ar.dashboard.products;

/**
 * V2 Task 06: downloads the products the list's filters match, in the import format (Excel, or CSV for other
 * tools). A large catalog is prepared by a job; the download starts by itself when the file is ready.
 */
export function ExportButtons({ query }: { query: ListQuery }) {
  const exporter = useExportProducts();

  const run = (format: SpreadsheetFormat) => {
    const waiting = toast.loading(copy.exporting);

    exporter.mutate(
      { query, format },
      {
        onSuccess: () => toast.dismiss(waiting),
        onError: (error) => {
          toast.dismiss(waiting);
          toast.error(messageFor(error));
        },
      },
    );
  };

  return (
    <div className="flex items-center gap-1">
      <Hint text={copy.exportHint}>
        <Button variant="outline" disabled={exporter.isPending} onClick={() => run("xlsx")}>
          <DownloadIcon />
          {exporter.isPending ? copy.exporting : copy.export}
        </Button>
      </Hint>
      <Button variant="ghost" size="sm" disabled={exporter.isPending} onClick={() => run("csv")}>
        {copy.exportCsv}
      </Button>
    </div>
  );
}
