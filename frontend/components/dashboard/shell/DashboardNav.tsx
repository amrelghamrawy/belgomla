"use client";

import {
  BarChart3Icon,
  BadgePercentIcon,
  BoxesIcon,
  FolderTreeIcon,
  HistoryIcon,
  LayoutDashboardIcon,
  type LucideIcon,
  PackageIcon,
  ReceiptIcon,
  RulerIcon,
  SettingsIcon,
  ShoppingBagIcon,
  SlidersHorizontalIcon,
  TagIcon,
  Undo2Icon,
  UsersIcon,
  UserCogIcon,
  WalletIcon,
} from "lucide-react";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { useEffect } from "react";

import {
  SidebarGroup,
  SidebarGroupContent,
  SidebarGroupLabel,
  SidebarMenu,
  SidebarMenuButton,
  SidebarMenuItem,
  useSidebar,
} from "@/components/ui/sidebar";
import { ar } from "@/lib/copy/ar";
import { type Permission, useCan } from "@/lib/dashboard/permissions";

/** `permissions`: any one of them shows the item (D82); none = every role. */
type NavItem = { href: string; label: string; icon: LucideIcon; permissions?: Permission[] };

const copy = ar.dashboard.nav;

export const NAV_SECTIONS: { title: string; items: NavItem[] }[] = [
  {
    title: copy.sales,
    items: [
      { href: "/dashboard", label: copy.overview, icon: LayoutDashboardIcon },
      { href: "/dashboard/orders", label: copy.orders, icon: ShoppingBagIcon },
      { href: "/dashboard/returns", label: copy.returns, icon: Undo2Icon },
      {
        href: "/dashboard/collections",
        label: copy.collections,
        icon: WalletIcon,
        permissions: ["collections.manage"],
      },
      { href: "/dashboard/expenses", label: copy.expenses, icon: ReceiptIcon, permissions: ["expenses.manage"] },
      {
        href: "/dashboard/customers",
        label: copy.customers,
        icon: UsersIcon,
        permissions: ["customers.manage", "customers.credit", "collections.manage"],
      },
      {
        href: "/dashboard/customer-groups",
        label: copy.customerGroups,
        icon: BadgePercentIcon,
        permissions: ["pricing.customers"],
      },
      { href: "/dashboard/reports", label: copy.reports, icon: BarChart3Icon, permissions: ["reports.view"] },
    ],
  },
  {
    title: copy.catalog,
    items: [
      { href: "/dashboard/products", label: copy.products, icon: PackageIcon },
      { href: "/dashboard/categories", label: copy.categories, icon: FolderTreeIcon },
      { href: "/dashboard/units", label: copy.units, icon: RulerIcon },
      { href: "/dashboard/fields", label: copy.fields, icon: SlidersHorizontalIcon },
      { href: "/dashboard/inventory", label: copy.inventory, icon: BoxesIcon },
      { href: "/dashboard/promotions", label: copy.promotions, icon: TagIcon, permissions: ["pricing.manage"] },
    ],
  },
  {
    title: copy.admin,
    items: [
      { href: "/dashboard/settings", label: copy.settings, icon: SettingsIcon, permissions: ["settings.manage"] },
      { href: "/dashboard/users", label: copy.users, icon: UserCogIcon, permissions: ["users.manage"] },
      { href: "/dashboard/audit", label: copy.audit, icon: HistoryIcon, permissions: ["audit.view"] },
    ],
  },
];

/** The overview is active only on /dashboard itself; every other item also covers its sub-pages. */
export function isActive(href: string, path: string): boolean {
  return href === "/dashboard" ? path === href : path === href || path.startsWith(`${href}/`);
}

/** WCAG 2.4.2 (D104): the tab names the section and the store, e.g. «الطلبات · مكتبة النور». */
export function pageTitle(path: string, storeName: string): string {
  const label = sectionLabel(path);

  return label === undefined ? storeName : `${label} · ${storeName}`;
}

/** The nav item the path belongs to, for the breadcrumb and the tab title. */
export function sectionLabel(path: string): string | undefined {
  return NAV_SECTIONS.flatMap((section) => section.items).find((entry) => isActive(entry.href, path))?.label;
}

/** D104: «لوحة التحكم / الطلبات» above the page, as in the reference design's navbar. */
export function DashboardBreadcrumb() {
  const label = sectionLabel(usePathname());

  return (
    <nav aria-label={copy.breadcrumb} className="text-muted-foreground hidden min-w-0 text-sm md:block">
      <ol className="flex items-center gap-1.5">
        <li>
          <Link href="/dashboard" className="hover:text-foreground">
            {copy.home}
          </Link>
        </li>
        {label !== undefined && label !== copy.overview && (
          <>
            <li aria-hidden>/</li>
            <li aria-current="page" className="text-foreground truncate font-medium">
              {label}
            </li>
          </>
        )}
      </ol>
    </nav>
  );
}

/** Keeps the document title on the current section while a dashboard page is open. */
export function DashboardTitle({ storeName }: { storeName: string }) {
  const path = usePathname();

  useEffect(() => {
    document.title = pageTitle(path, storeName);
  }, [path, storeName]);

  return null;
}

/**
 * The dashboard's sections inside the sidebar: labelled groups on a wide screen, icons with tooltips when the
 * sidebar is collapsed, and on phones a drawer that closes once an item is picked.
 */
export function DashboardNav() {
  const path = usePathname();
  const { isMobile, setOpenMobile } = useSidebar();
  const allowed = useCan();
  const sections = NAV_SECTIONS.map((section) => ({
    ...section,
    items: section.items.filter((item) => item.permissions === undefined || allowed(...item.permissions)),
  })).filter((section) => section.items.length > 0);

  return (
    <nav aria-label={copy.label}>
      {sections.map((section) => (
        <SidebarGroup key={section.title} className="px-4">
          <SidebarGroupLabel className="text-sidebar-foreground text-xs font-bold">{section.title}</SidebarGroupLabel>
          <SidebarGroupContent>
            <SidebarMenu className="gap-0.5">
              {section.items.map((item) => {
                const active = isActive(item.href, path);

                return (
                  <SidebarMenuItem key={item.href}>
                    {/* D104: the open section is ink and bold with a brand icon, and a brand bar on the sidebar's inner edge. */}
                    {active && (
                      <span
                        aria-hidden
                        className="bg-sidebar-primary absolute -end-2 top-1/2 h-9 w-1 -translate-y-1/2 rounded-full group-data-[collapsible=icon]:hidden"
                      />
                    )}
                    <SidebarMenuButton
                      asChild
                      isActive={active}
                      tooltip={item.label}
                      className="text-sidebar-foreground hover:text-sidebar-accent-foreground data-active:text-sidebar-accent-foreground data-active:[&>svg]:text-sidebar-primary h-11 gap-3.5 ps-3 text-[0.95rem] font-medium hover:bg-transparent data-active:bg-transparent data-active:font-bold data-active:hover:bg-transparent [&>svg]:size-5"
                    >
                      <Link
                        href={item.href}
                        aria-current={active ? "page" : undefined}
                        onClick={() => isMobile && setOpenMobile(false)}
                      >
                        <item.icon aria-hidden />
                        <span>{item.label}</span>
                      </Link>
                    </SidebarMenuButton>
                  </SidebarMenuItem>
                );
              })}
            </SidebarMenu>
          </SidebarGroupContent>
        </SidebarGroup>
      ))}
    </nav>
  );
}
