"use client";

import {
  BoxesIcon,
  LayoutDashboardIcon,
  type LucideIcon,
  PackageIcon,
  ScaleIcon,
  TrendingUpIcon,
  UsersIcon,
  WalletIcon,
} from "lucide-react";
import { useEffect, useRef } from "react";

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

export const REPORT_TABS = [
  "overview",
  "sales",
  "products",
  "customers",
  "inventory",
  "collections",
  "profit-and-loss",
] as const;

type ReportTab = (typeof REPORT_TABS)[number];

/** D104: each report's icon, so a tab is found by shape before it is read. */
const TAB_ICONS: Record<ReportTab, LucideIcon> = {
  overview: LayoutDashboardIcon,
  sales: TrendingUpIcon,
  products: PackageIcon,
  customers: UsersIcon,
  inventory: BoxesIcon,
  collections: WalletIcon,
  "profit-and-loss": ScaleIcon,
};

const isTab = (value: string | undefined): value is ReportTab => REPORT_TABS.some((tab) => tab === value);

/**
 * The reports (D5, V2 Task 16 / D94) as tabs kept in the URL (`?tab=`): the V1 overview first, then the advanced
 * reports. The V1 lists live on in them: outstanding per customer under التحصيل, low stock under المخزون, the
 * bundle report under المنتجات. The range is shared by the tabs; only the open tab loads.
 */
export function ReportsPage() {
  const [query, setQuery] = useListQuery(REPORT_QUERY_KEYS);
  const tab: ReportTab = isTab(query.tab) ? query.tab : "overview";
  const list = useRef<HTMLDivElement>(null);

  // On a phone the bar scrolls sideways: bring the open report into view (on arrival from a link too).
  useEffect(() => {
    list.current?.querySelector<HTMLElement>('[data-state="active"]')?.scrollIntoView?.({
      block: "nearest",
      inline: "nearest",
    });
  }, [tab]);

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
        <TabsList
          ref={list}
          className="w-full max-w-full snap-x [scrollbar-width:none] justify-start overflow-x-auto lg:w-fit"
        >
          {REPORT_TABS.map((value) => {
            const Icon = TAB_ICONS[value];

            return (
              <TabsTrigger key={value} value={value} className="flex-none snap-start">
                <Icon aria-hidden />
                {copy.tabs[value]}
              </TabsTrigger>
            );
          })}
        </TabsList>
        <p className="text-muted-foreground pt-3 text-sm" data-testid="report-hint">
          {copy.tabHints[tab]}
        </p>
        <TabsContent value="overview" className="flex min-w-0 flex-col gap-4 pt-2">
          <OverviewBody />
        </TabsContent>
        <TabsContent value="sales" className="min-w-0 pt-2">
          <SalesTab query={query} setQuery={setQuery} />
        </TabsContent>
        <TabsContent value="products" className="min-w-0 pt-2">
          <ProductsTab query={query} setQuery={setQuery} />
        </TabsContent>
        <TabsContent value="customers" className="min-w-0 pt-2">
          <CustomersTab query={query} setQuery={setQuery} />
        </TabsContent>
        <TabsContent value="inventory" className="min-w-0 pt-2">
          <InventoryTab query={query} setQuery={setQuery} />
        </TabsContent>
        <TabsContent value="collections" className="min-w-0 pt-2">
          <CollectionsTab query={query} setQuery={setQuery} />
        </TabsContent>
        <TabsContent value="profit-and-loss" className="min-w-0 pt-2">
          <ProfitAndLossTab query={query} setQuery={setQuery} />
        </TabsContent>
      </Tabs>
    </>
  );
}
