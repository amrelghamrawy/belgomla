"use client";

import {
  BarChart3Icon,
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
  UsersIcon,
  UserCogIcon,
  WalletIcon,
} from "lucide-react";
import Link from "next/link";
import { usePathname } from "next/navigation";

import { ar } from "@/lib/copy/ar";
import { cn } from "@/lib/utils";

type NavItem = { href: string; label: string; icon: LucideIcon };

const copy = ar.dashboard.nav;

export const NAV_SECTIONS: { title: string; items: NavItem[] }[] = [
  {
    title: copy.sales,
    items: [
      { href: "/dashboard", label: copy.overview, icon: LayoutDashboardIcon },
      { href: "/dashboard/orders", label: copy.orders, icon: ShoppingBagIcon },
      { href: "/dashboard/collections", label: copy.collections, icon: WalletIcon },
      { href: "/dashboard/customers", label: copy.customers, icon: UsersIcon },
      { href: "/dashboard/reports", label: copy.reports, icon: BarChart3Icon },
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
    ],
  },
  {
    title: copy.admin,
    items: [
      { href: "/dashboard/settings", label: copy.settings, icon: SettingsIcon },
      { href: "/dashboard/users", label: copy.users, icon: UserCogIcon },
      { href: "/dashboard/audit", label: copy.audit, icon: HistoryIcon },
    ],
  },
];

/** The overview is active only on /dashboard itself; every other item also covers its sub-pages. */
export function isActive(href: string, path: string): boolean {
  return href === "/dashboard" ? path === href : path === href || path.startsWith(`${href}/`);
}

/** The dashboard's sections, shared by the desktop sidebar and the phone drawer. */
export function DashboardNav({ onNavigate }: { onNavigate?: () => void }) {
  const path = usePathname();

  return (
    <nav aria-label={copy.label} className="flex flex-col gap-4 p-3">
      {NAV_SECTIONS.map((section) => (
        <div key={section.title} className="flex flex-col gap-1">
          <p className="text-muted-foreground px-3 text-xs font-medium">{section.title}</p>
          {section.items.map((item) => {
            const active = isActive(item.href, path);

            return (
              <Link
                key={item.href}
                href={item.href}
                onClick={onNavigate}
                aria-current={active ? "page" : undefined}
                className={cn(
                  "flex h-10 items-center gap-3 rounded-md px-3 text-sm transition-colors",
                  active ? "bg-primary text-primary-foreground" : "hover:bg-muted",
                )}
              >
                <item.icon className="size-4 shrink-0" aria-hidden />
                {item.label}
              </Link>
            );
          })}
        </div>
      ))}
    </nav>
  );
}
