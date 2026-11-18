"use client";

import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { ar } from "@/lib/copy/ar";
import { REPORT_QUERY_KEYS } from "@/lib/dashboard/report-range";
import { useListQuery } from "@/lib/dashboard/use-list-query";

import { PageHeader } from "../common/States";
import { OverviewBody } from "../overview/Overview";
import { CollectionsTab } from "./CollectionsTab";
import { CustomersTab } from "./CustomersTab";
import { InventoryTab } from "./InventoryTab";
import { ProductsTab } from "./ProductsTab";
import { ProfitAndLossTab } from "./ProfitAndLossTab";
import { SalesTab } from "./SalesTab";

const copy = ar.dashboard.reports;

export const REPORT_TABS = ["overview", "sales", "products", "customers", "inventory", "collections", "profit-and-loss"] as const;

type ReportTab = (typeof REPORT_TABS)[number];

const isTab = (value: string | undefined): value is ReportTab => REPORT_TABS.some((tab) => tab === value);

/**
 * The reports (D5, V2 Task 16 / D94) as tabs kept in the URL (`?tab=`): the V1 overview first, then the advanced
 * reports. The V1 lists live on in them: outstanding per customer under التحصيل, low stock under المخزون, the
 * bundle report under المنتجات. The range is shared by the tabs; only the open tab loads.
 */
export function ReportsPage() {
  const [query, setQuery] = useListQuery(REPORT_QUERY_KEYS);
  const tab: ReportTab = isTab(query.tab) ? query.tab : "overview";

  return (
    <>
      <PageHeader title={copy.title} description={copy.description} />
      <Tabs
        dir="rtl"
        value={tab}
        onValueChange={(value) =>
          setQuery({ tab: value === "overview" ? null : value, low_page: null, debt_page: null })
        }
      >
        <TabsList className="w-full justify-start overflow-x-auto sm:w-fit">
          {REPORT_TABS.map((value) => (
            <TabsTrigger key={value} value={value}>
              {copy.tabs[value]}
            </TabsTrigger>
          ))}
        </TabsList>
        <TabsContent value="overview" className="flex min-w-0 flex-col gap-4 pt-4">
          <OverviewBody />
        </TabsContent>
        <TabsContent value="sales" className="min-w-0 pt-4">
          <SalesTab query={query} setQuery={setQuery} />
        </TabsContent>
        <TabsContent value="products" className="min-w-0 pt-4">
          <ProductsTab query={query} setQuery={setQuery} />
        </TabsContent>
        <TabsContent value="customers" className="min-w-0 pt-4">
          <CustomersTab query={query} setQuery={setQuery} />
        </TabsContent>
        <TabsContent value="inventory" className="min-w-0 pt-4">
          <InventoryTab query={query} setQuery={setQuery} />
        </TabsContent>
        <TabsContent value="collections" className="min-w-0 pt-4">
          <CollectionsTab query={query} setQuery={setQuery} />
        </TabsContent>
        <TabsContent value="profit-and-loss" className="min-w-0 pt-4">
          <ProfitAndLossTab query={query} setQuery={setQuery} />
        </TabsContent>
      </Tabs>
    </>
  );
}
