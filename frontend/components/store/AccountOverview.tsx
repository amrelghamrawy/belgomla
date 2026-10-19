"use client";

import { ChevronRightIcon, LogOutIcon, MapPinIcon, PackageIcon, ReceiptTextIcon } from "lucide-react";
import Link from "next/link";
import { toast } from "sonner";

import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { Switch } from "@/components/ui/switch";
import { useLogout, useRequiredCustomer } from "@/lib/auth/session";
import { ar, messageFor } from "@/lib/copy/ar";
import { formatDate } from "@/lib/format/date";
import { formatPhone } from "@/lib/format/phone";
import { useUpdatePreferences } from "@/lib/store/preferences";

/** `/account`: who is logged in, the order-message switch (BR-N03), links to orders, the statement and addresses, logout. The profile is read-only (D63). */
export function AccountOverview() {
  const { data: customer } = useRequiredCustomer();
  const logout = useLogout("store");
  const preferences = useUpdatePreferences();

  if (!customer) {
    return null;
  }

  const links = [
    { href: "/account/orders", label: ar.account.orders, icon: PackageIcon },
    { href: "/account/statement", label: ar.account.statement, icon: ReceiptTextIcon },
    { href: "/account/addresses", label: ar.account.addresses, icon: MapPinIcon },
  ];

  return (
    <main className="mx-auto flex w-full max-w-2xl flex-1 flex-col gap-4 px-4 py-6">
      <h1 className="text-xl font-bold">{ar.account.title}</h1>
      <section className="flex flex-col gap-1 rounded-xl border p-4">
        <p className="text-lg font-bold">{customer.name}</p>
        <bdi className="text-muted-foreground">{formatPhone(customer.phone)}</bdi>
        <p className="text-sm">
          {ar.account.status}: {customer.status_label}
        </p>
        <p className="text-muted-foreground text-sm">
          {ar.account.memberSince} {formatDate(customer.created_at)}
        </p>
        <p className="text-muted-foreground text-xs">{ar.account.profileReadOnly}</p>
      </section>
      <section className="flex items-start gap-3 rounded-xl border p-4">
        <div className="flex flex-1 flex-col gap-1">
          <Label htmlFor="account-order-notifications">{ar.account.orderNotifications}</Label>
          <p className="text-muted-foreground text-xs">{ar.account.orderNotificationsHint}</p>
        </div>
        <Switch
          id="account-order-notifications"
          checked={customer.order_notifications}
          disabled={preferences.isPending}
          onCheckedChange={(checked) =>
            preferences.mutate(
              { order_notifications: checked },
              {
                onSuccess: () => toast.success(ar.account.preferencesSaved),
                onError: (failure) => toast.error(messageFor(failure)),
              },
            )
          }
        />
      </section>
      {customer.status !== "active" && (
        <Alert>
          <AlertDescription>{ar.errors[customer.status]}</AlertDescription>
        </Alert>
      )}
      <nav>
        <ul className="flex flex-col divide-y rounded-xl border">
          {links.map(({ href, label, icon: Icon }) => (
            <li key={href}>
              <Link href={href} className="hover:bg-muted/50 flex min-h-14 items-center gap-3 px-4">
                <Icon className="text-primary size-5" aria-hidden />
                <span className="flex-1">{label}</span>
                <ChevronRightIcon className="text-muted-foreground size-4 rtl:rotate-180" aria-hidden />
              </Link>
            </li>
          ))}
        </ul>
      </nav>
      <Button variant="outline" className="h-11" disabled={logout.isPending} onClick={() => logout.mutate()}>
        <LogOutIcon />
        {ar.auth.logout}
      </Button>
    </main>
  );
}
