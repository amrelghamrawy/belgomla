"use client";

import { DownloadIcon } from "lucide-react";
import type { ReactNode } from "react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { NativeSelect } from "@/components/ui/native-select";
import type { ReportName, SalesGrouping, SpreadsheetFormat } from "@/lib/api/types";
import { ar, messageFor } from "@/lib/copy/ar";
import { useDashboardCategories } from "@/lib/dashboard/catalog";
import { activeOnly, flatten } from "@/lib/dashboard/category-tree";
import { useCustomerGroups } from "@/lib/dashboard/customer-pricing";
import { activeFilters, type ListQuery } from "@/lib/dashboard/list-query";
import { useCan } from "@/lib/dashboard/permissions";
import {
  activePreset,
  cairoToday,
  presetRange,
  RANGE_PRESETS,
  REPORT_KEYS,
  reportParams,
} from "@/lib/dashboard/report-range";
import { useExportReport } from "@/lib/dashboard/reports";
import { formatDateTime, formatDay } from "@/lib/format/date";

import { FilterPanel } from "../common/FilterPanel";
import { Hint } from "../common/Hint";

const copy = ar.dashboard.reports;
const GROUPS: SalesGrouping[] = ["day", "week", "month"];

export type SetQuery = (patch: Record<string, string | number | null>) => void;

type Props = {
  report: ReportName;
  query: ListQuery;
  setQuery: SetQuery;
  /** The range the report answered (the defaults when the URL has none). */
  range?: { from: string; to: string };
  generatedAt?: string;
  /** The report's own options (sort, view, thresholds), inside the filter panel. */
  children?: ReactNode;
};

/**
 * The filters of an advanced report (V2 Task 16): the range presets beside the filter button, and in the panel the
 * dates and whichever of grouping, category and customer group the report reads (REPORT_KEYS); then the range shown,
 * «آخر تحديث» (D94: the figures are cached) and the export of every row with the same filters.
 */
export function ReportToolbar({ report, query, setQuery, range, generatedAt, children }: Props) {
  const allowed = useCan();
  const keys = REPORT_KEYS[report];
  const today = cairoToday();
  const pressed = activePreset(query.from, query.to, today);
  const categories = useDashboardCategories(keys.includes("category_id") && allowed("orders.view"));
  const groups = useCustomerGroups(keys.includes("customer_group_id") && allowed("pricing.customers"));

  return (
    <div className="flex flex-col gap-2">
      <FilterPanel
        active={activeFilters(query, keys)}
        leading={
          <div className="flex flex-wrap items-center gap-1" role="group" aria-label={copy.period}>
            {RANGE_PRESETS.map((preset) => (
              <Button
                key={preset}
                variant={pressed === preset ? "default" : "outline"}
                size="sm"
                aria-pressed={pressed === preset}
                onClick={() => setQuery(presetRange(preset, today))}
              >
                {copy.presets[preset]}
              </Button>
            ))}
          </div>
        }
      >
        <label className="flex flex-col gap-1 text-xs">
          {ar.dashboard.list.from}
          <Input
            type="date"
            value={query.from ?? range?.from ?? ""}
            onChange={(event) => setQuery({ from: event.target.value })}
          />
        </label>
        <label className="flex flex-col gap-1 text-xs">
          {ar.dashboard.list.to}
          <Input
            type="date"
            value={query.to ?? range?.to ?? ""}
            onChange={(event) => setQuery({ to: event.target.value })}
          />
        </label>
        {keys.includes("group") && (
          <NativeSelect
            aria-label={copy.group}
            value={query.group ?? "day"}
            onChange={(event) => setQuery({ group: event.target.value === "day" ? null : event.target.value })}
          >
            {GROUPS.map((group) => (
              <option key={group} value={group}>
                {copy.groups[group]}
              </option>
            ))}
          </NativeSelect>
        )}
        {categories.data !== undefined && (
          <NativeSelect
            aria-label={copy.category}
            value={query.category_id ?? ""}
            onChange={(event) => setQuery({ category_id: event.target.value })}
          >
            <option value="">{copy.allCategories}</option>
            {flatten(activeOnly(categories.data)).map(({ node, depth }) => (
              <option key={node.id} value={node.id}>
                {`${"— ".repeat(depth)}${node.name}`}
              </option>
            ))}
          </NativeSelect>
        )}
        {groups.data !== undefined && (
          <NativeSelect
            aria-label={copy.customerGroup}
            value={query.customer_group_id ?? ""}
            onChange={(event) => setQuery({ customer_group_id: event.target.value })}
          >
            <option value="">{copy.allGroups}</option>
            {groups.data.map((group) => (
              <option key={group.id} value={group.id}>
                {group.name}
              </option>
            ))}
          </NativeSelect>
        )}
        {children}
      </FilterPanel>
      <div className="text-muted-foreground flex flex-wrap items-center justify-between gap-2 text-xs">
        <div className="flex min-w-0 flex-wrap gap-x-3">
          {range !== undefined && <span>{copy.rangeLabel(formatDay(range.from), formatDay(range.to))}</span>}
          {generatedAt !== undefined && (
            <span data-testid="generated-at">{copy.generatedAt(formatDateTime(generatedAt))}</span>
          )}
        </div>
        <ReportExportButtons report={report} params={reportParams(report, query)} />
      </div>
    </div>
  );
}

/** «تصدير»: every row of the report (not one page) with the same filters and options, as Excel, or CSV (D94 k). */
export function ReportExportButtons({ report, params }: { report: ReportName; params: Record<string, string> }) {
  const exporter = useExportReport();

  const run = (format: SpreadsheetFormat) => {
    const waiting = toast.loading(copy.exporting);

    exporter.mutate(
      { report, params, format },
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
        <Button variant="outline" size="sm" disabled={exporter.isPending} onClick={() => run("xlsx")}>
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
