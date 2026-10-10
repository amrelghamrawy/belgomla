"use client";

import { ExternalLinkIcon, LogOutIcon, UserIcon } from "lucide-react";

import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import type { TenantUser } from "@/lib/api/types";
import { useLogout } from "@/lib/auth/session";
import { ar } from "@/lib/copy/ar";

/** Who is logged in, a link to the storefront, and logout. */
export function UserMenu({ user }: { user: TenantUser }) {
  const logout = useLogout("dashboard");

  return (
    <DropdownMenu dir="rtl">
      <DropdownMenuTrigger asChild>
        <Button variant="ghost" size="icon" className="size-11" aria-label={ar.dashboard.userMenu}>
          <UserIcon />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-60">
        <DropdownMenuLabel className="flex flex-col gap-0.5">
          <span data-testid="user-name">{user.name}</span>
          <bdi className="text-muted-foreground text-xs font-normal">{user.email}</bdi>
        </DropdownMenuLabel>
        <DropdownMenuSeparator />
        <DropdownMenuItem asChild>
          <a href="/" target="_blank" rel="noreferrer">
            <ExternalLinkIcon />
            {ar.dashboard.viewStore}
          </a>
        </DropdownMenuItem>
        <DropdownMenuItem disabled={logout.isPending} onSelect={() => logout.mutate()}>
          <LogOutIcon />
          {ar.auth.logout}
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}
