"use client";

import Link from "next/link";

import { useTenant } from "@/lib/auth/tenant";
import { ar } from "@/lib/copy/ar";
import { formatPhone, whatsappLink } from "@/lib/format/phone";

import { StoreMark } from "./StoreMark";

/** Contact details and opening hours from the store settings (01 §7), with the store's mark (D67). */
export function StoreFooter() {
  const { data: tenant } = useTenant();

  if (tenant === undefined) {
    return null;
  }

  return (
    <footer className="bg-card mt-auto border-t print:hidden">
      <div className="mx-auto grid w-full max-w-6xl gap-6 px-4 py-8 text-sm md:grid-cols-[1.4fr_1fr_1fr]">
        <div className="flex flex-col gap-3">
          <Link href="/" className="flex items-center gap-2.5">
            <StoreMark name={tenant.name} logoUrl={tenant.logo_url} />
            <span className="font-display text-primary text-lg font-bold">{tenant.name}</span>
          </Link>
          {tenant.description && <p className="text-muted-foreground max-w-[40ch]">{tenant.description}</p>}
          <Link href="/price-list" className="text-primary font-semibold underline-offset-4 hover:underline">
            {ar.store.priceList}
          </Link>
        </div>
        <div className="flex flex-col gap-1.5">
          <p className="text-muted-foreground text-xs font-semibold">{ar.store.contactUs}</p>
          {tenant.address && (
            <p>
              {ar.store.address}: {tenant.address}
            </p>
          )}
          {tenant.phone && (
            <p>
              {ar.store.phone}:{" "}
              <a href={`tel:${tenant.phone}`} className="font-semibold underline-offset-4 hover:underline">
                <bdi>{formatPhone(tenant.phone)}</bdi>
              </a>
            </p>
          )}
          {tenant.whatsapp && (
            <p>
              {ar.store.whatsapp}:{" "}
              <a
                href={whatsappLink(tenant.whatsapp)}
                className="font-semibold underline-offset-4 hover:underline"
                rel="noopener"
              >
                <bdi>{formatPhone(tenant.whatsapp)}</bdi>
              </a>
            </p>
          )}
        </div>
        {tenant.working_hours.length > 0 && (
          <div className="flex flex-col gap-1.5">
            <p className="text-muted-foreground text-xs font-semibold">{ar.store.workingHours}</p>
            <ul className="flex flex-col gap-0.5">
              {tenant.working_hours.map((slot) => (
                <li key={`${slot.day}-${slot.from}`} className="flex justify-between gap-4 md:max-w-56">
                  <span>{ar.store.days[slot.day]}</span>
                  <bdi className="tabular-nums">
                    {slot.from}–{slot.to}
                  </bdi>
                </li>
              ))}
            </ul>
          </div>
        )}
      </div>
      <p className="text-muted-foreground mx-auto w-full max-w-6xl border-t px-4 py-3 text-xs">
        {ar.store.rights(tenant.name)}
      </p>
    </footer>
  );
}
