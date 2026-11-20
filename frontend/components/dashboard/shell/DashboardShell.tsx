"use client";

import Image from "next/image";
import Link from "next/link";
import { type CSSProperties, type ReactNode, useState } from "react";

import { StatusPage } from "@/components/shared/StatusPage";
import {
  Sidebar,
  SidebarContent,
  SidebarHeader,
  SidebarProvider,
  SidebarRail,
  SidebarTrigger,
  useSidebar,
} from "@/components/ui/sidebar";
import { Skeleton } from "@/components/ui/skeleton";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import type { TenantSettings } from "@/lib/api/types";
import { useRequiredDashboardUser } from "@/lib/auth/session";
import { useTenant } from "@/lib/auth/tenant";
import { ar, messageFor } from "@/lib/copy/ar";

import { StoreWhatsAppBanner } from "../admin/StoreWhatsAppCard";
import { DashboardBreadcrumb, DashboardNav, DashboardTitle } from "./DashboardNav";
import { NotificationsBell } from "./NotificationsBell";
import { OrderSearch } from "./OrderSearch";
import { UserMenu } from "./UserMenu";

const copy = ar.dashboard.nav;

/** The sidebar's last state, which the shadcn sidebar keeps in the `sidebar_state` cookie; open by default. */
function sidebarWasOpen(): boolean {
  return typeof document === "undefined" || !document.cookie.split("; ").includes("sidebar_state=false");
}

/**
 * The frame of every dashboard page except login: the session guard, a sidebar at the start side (the right
 * in RTL) that collapses to icons on wide screens (Ctrl+B) and is a drawer on phones, the tenant's name and
 * logo, the bell and the user menu. Pages render inside `<main>`, which stays hidden until the session is
 * known: the page is always rendered (so the instant-navigation validation of cacheComponents sees it), but
 * nothing shows to a guest, whose own calls get a 401 and are sent to the login like the guard does.
 */
export function DashboardShell({ children }: { children: ReactNode }) {
  const session = useRequiredDashboardUser();
  const [defaultOpen] = useState(sidebarWasOpen);

  if (session.isError) {
    return <StatusPage title={ar.pages.errorTitle} body={messageFor(session.error)} />;
  }

  const me = session.data;

  if (!me) {
    return (
      <>
        <ShellSkeleton />
        <main hidden>{children}</main>
      </>
    );
  }

  return (
    <SidebarProvider defaultOpen={defaultOpen} style={{ "--sidebar-width": "17.5rem" } as CSSProperties}>
      <DashboardTitle storeName={me.tenant.name} />
      <Sidebar side="start" collapsible="icon" label={copy.label} className="print:hidden">
        <SidebarHeader className="border-sidebar-border gap-4 border-b px-5 pt-7 pb-5 group-data-[collapsible=icon]:px-2">
          <PlatformMark />
          <DesktopBrand name={me.tenant.name} />
        </SidebarHeader>
        <SidebarContent className="pt-2">
          <DashboardNav />
        </SidebarContent>
        {/* A drag-edge for the mouse; keyboard and screen-reader users have the trigger in the top bar. */}
        <SidebarRail aria-hidden />
      </Sidebar>
      <div className="flex min-w-0 flex-1 flex-col">
        {/* D104: the reference design's floating bar: the breadcrumb at the start, a white pill of tools at the end. */}
        <header className="bg-background/80 sticky top-0 z-40 flex min-h-16 items-center gap-2 px-3 pt-3 pb-2 backdrop-blur-xl sm:px-5 md:px-7 print:hidden">
          <Tooltip>
            <TooltipTrigger asChild>
              <SidebarTrigger aria-label={copy.toggle} className="bg-card size-11 rounded-full shadow-panel md:size-10" />
            </TooltipTrigger>
            <TooltipContent side="bottom">{copy.toggleHint}</TooltipContent>
          </Tooltip>
          <MobileBrand name={me.tenant.name} />
          <div className="bg-card shadow-panel flex items-center gap-1 rounded-full p-1.5 md:gap-2 md:p-2">
            <OrderSearch />
            <NotificationsBell />
            <UserMenu user={me.user} />
          </div>
        </header>
        <StoreWhatsAppBanner />
        <main className="mx-auto flex w-full max-w-[1600px] min-w-0 flex-1 flex-col gap-6 px-4 pt-3 pb-8 md:px-7 print:p-0">
          {children}
        </main>
      </div>
    </SidebarProvider>
  );
}

/** D104: BelGomla's wordmark heads the sidebar, as the product name heads the reference design's. */
function PlatformMark() {
  return (
    <Link
      href="/dashboard"
      className="font-heading text-sidebar-accent-foreground text-center text-[1.65rem] leading-none font-bold group-data-[collapsible=icon]:hidden"
    >
      {ar.appName}
    </Link>
  );
}

type BrandProps = {
  name: string;
  tenant: TenantSettings | undefined;
  className: string;
  /** Only one copy on the page carries the test id: the drawer copy on phones stays unmarked. */
  testId?: string;
};

/** D104: the store's logo (or initial) and name inside BelGomla's frame; the store's colours stay on the storefront. */
function Brand({ name, tenant, className, testId }: BrandProps) {
  return (
    <Link href="/dashboard" className={className}>
      {tenant?.logo_url ? (
        <Image
          src={tenant.logo_url}
          alt=""
          width={40}
          height={40}
          className="bg-card size-10 shrink-0 rounded-full object-contain p-0.5"
        />
      ) : (
        <span
          aria-hidden
          className="bg-primary-soft text-primary font-heading flex size-10 shrink-0 items-center justify-center rounded-full text-base font-bold"
        >
          {name.slice(0, 1)}
        </span>
      )}
      <span className="flex min-w-0 flex-col group-data-[collapsible=icon]:hidden">
        <span data-testid={testId} className="font-heading text-foreground truncate leading-tight font-bold">
          {name}
        </span>
        <span className="text-muted-foreground text-xs">{copy.platform}</span>
      </span>
    </Link>
  );
}

/** The tenant under the wordmark (and in the phone drawer); only the logo shows when the sidebar is collapsed. */
function DesktopBrand({ name }: { name: string }) {
  const { isMobile } = useSidebar();
  const { data: tenant } = useTenant();

  return (
    <Brand
      name={name}
      tenant={tenant}
      testId={isMobile ? undefined : "tenant-name"}
      className="bg-muted flex min-w-0 items-center gap-3 rounded-2xl p-2.5 group-data-[collapsible=icon]:bg-transparent group-data-[collapsible=icon]:p-0"
    />
  );
}

/** On phones the sidebar is a closed drawer, so the tenant sits in the top bar; on wide screens, the breadcrumb. */
function MobileBrand({ name }: { name: string }) {
  const { isMobile } = useSidebar();
  const { data: tenant } = useTenant();

  return isMobile ? (
    <Brand name={name} tenant={tenant} testId="tenant-name" className="flex min-w-0 flex-1 items-center gap-2" />
  ) : (
    <div className="flex min-w-0 flex-1 items-center">
      <DashboardBreadcrumb />
    </div>
  );
}

/** The frame's outline while the session loads, so the page does not jump when it arrives. */
function ShellSkeleton() {
  return (
    <div className="flex min-h-svh" aria-busy="true" aria-label={ar.loading}>
      <div className="hidden w-64 shrink-0 flex-col gap-3 border-e p-3 md:flex">
        <Skeleton className="h-8 w-40" />
        {Array.from({ length: 8 }, (_, index) => (
          <Skeleton key={index} className="h-8 w-full" />
        ))}
      </div>
      <div className="flex flex-1 flex-col">
        <div className="flex h-14 items-center gap-2 border-b px-4">
          <Skeleton className="size-8" />
          <Skeleton className="h-5 w-32" />
        </div>
        <div className="flex flex-col gap-3 p-4 md:p-6">
          <Skeleton className="h-8 w-48" />
          <Skeleton className="h-32 w-full" />
        </div>
      </div>
    </div>
  );
}
