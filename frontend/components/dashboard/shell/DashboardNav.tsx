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
        <SidebarGroup key={section.title}>
          <SidebarGroupLabel>{section.title}</SidebarGroupLabel>
          <SidebarGroupContent>
            <SidebarMenu className="gap-0.5">
              {section.items.map((item) => {
                const active = isActive(item.href, path);

                return (
                  <SidebarMenuItem key={item.href}>
                    <SidebarMenuButton
                      asChild
                      isActive={active}
                      tooltip={item.label}
                      className="data-active:bg-sidebar-primary data-active:text-sidebar-primary-foreground data-active:hover:bg-sidebar-primary/90 data-active:hover:text-sidebar-primary-foreground h-10"
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
