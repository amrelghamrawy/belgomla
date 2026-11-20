"use client";

import { type ReactNode, useEffect } from "react";

import { cn } from "@/lib/utils";

export const DASH_THEME = "dash-theme";

/**
 * D104: the dashboard wears BelGomla's own identity, whatever the tenant's colours. The theme class sits on a
 * wrapper for the first paint and on <html> while mounted, so dialogs, sheets, menus and toasts that portal to
 * <body> wear it too; leaving the dashboard (to the storefront) takes it off again.
 */
export function DashboardTheme({ fontClassName, children }: { fontClassName: string; children: ReactNode }) {
  useEffect(() => {
    const classes = [DASH_THEME, ...fontClassName.split(" ").filter(Boolean)];
    document.documentElement.classList.add(...classes);

    return () => document.documentElement.classList.remove(...classes);
  }, [fontClassName]);

  return (
    <div className={cn(DASH_THEME, fontClassName, "bg-background text-foreground flex flex-1 flex-col")}>{children}</div>
  );
}
