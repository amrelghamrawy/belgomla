"use client";

import { useTenant } from "@/lib/auth/tenant";
import { ar } from "@/lib/copy/ar";
import { formatPhone, whatsappLink } from "@/lib/format/phone";

/** Contact details and opening hours from the store settings (01 §7). */
export function StoreFooter() {
  const { data: tenant } = useTenant();

  if (tenant === undefined) {
    return null;
  }

  return (
    <footer className="bg-muted/40 mt-auto border-t print:hidden">
      <div className="text-muted-foreground mx-auto grid w-full max-w-6xl gap-4 px-4 py-6 text-sm sm:grid-cols-2">
        <div className="flex flex-col gap-1">
          <p className="text-foreground font-bold">{tenant.name}</p>
          {tenant.description && <p>{tenant.description}</p>}
          {tenant.address && (
            <p>
              {ar.store.address}: {tenant.address}
            </p>
          )}
          {tenant.phone && (
            <p>
              {ar.store.phone}:{" "}
              <a href={`tel:${tenant.phone}`} className="underline-offset-4 hover:underline">
                <bdi>{formatPhone(tenant.phone)}</bdi>
              </a>
            </p>
          )}
          {tenant.whatsapp && (
            <p>
              {ar.store.whatsapp}:{" "}
              <a href={whatsappLink(tenant.whatsapp)} className="underline-offset-4 hover:underline" rel="noopener">
                <bdi>{formatPhone(tenant.whatsapp)}</bdi>
              </a>
            </p>
          )}
        </div>
        {tenant.working_hours.length > 0 && (
          <div className="flex flex-col gap-1">
            <p className="text-foreground font-bold">{ar.store.workingHours}</p>
            <ul>
              {tenant.working_hours.map((slot) => (
                <li key={`${slot.day}-${slot.from}`}>
                  {ar.store.days[slot.day]}:{" "}
                  <bdi>
                    {slot.from}–{slot.to}
                  </bdi>
                </li>
              ))}
            </ul>
          </div>
        )}
      </div>
    </footer>
  );
}
