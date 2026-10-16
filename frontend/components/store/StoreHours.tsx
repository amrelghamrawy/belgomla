"use client";

import type { TenantSettings } from "@/lib/api/types";
import { ar } from "@/lib/copy/ar";
import { formatClock } from "@/lib/format/date";
import { cairoClock, groupWorkingHours, openStatus, useMinuteClock } from "@/lib/store/working-hours";
import { cn } from "@/lib/utils";

const dayName = (day: number) => ar.store.days[day] ?? "";

/**
 * The footer's opening hours: neighbouring days with the same hours on one row, closed days marked, today
 * highlighted, and whether the store is open right now on Cairo time (the last two only after hydration).
 */
export function StoreHours({ slots }: { slots: TenantSettings["working_hours"] }) {
  const now = useMinuteClock();
  const clock = now === null ? null : cairoClock(now);
  const status = clock === null ? null : openStatus(slots, clock);
  const groups = groupWorkingHours(slots);

  return (
    <div className="flex flex-col gap-2.5">
      <div className="flex max-w-72 flex-wrap items-center justify-between gap-2">
        <p className="text-muted-foreground text-xs font-semibold">{ar.store.workingHours}</p>
        {status && (
          <p
            className={cn(
              "inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold",
              status.open ? "bg-emerald-100 text-emerald-800" : "bg-muted text-muted-foreground",
            )}
          >
            <span
              aria-hidden
              className={cn("size-1.5 rounded-full", status.open ? "bg-emerald-600" : "bg-muted-foreground")}
            />
            {status.open ? ar.store.openNow : ar.store.closedNow}
            {status.open && <span className="font-normal">· {ar.store.closesAt(formatClock(status.closesAt))}</span>}
            {!status.open && status.opensAt && (
              <span className="font-normal">
                ·{" "}
                {ar.store.opensAt(status.opensAt.inDays, dayName(status.opensAt.day), formatClock(status.opensAt.from))}
              </span>
            )}
          </p>
        )}
      </div>
      <ul className="flex max-w-72 flex-col gap-0.5">
        {groups.map((group) => {
          const first = group.days[0] ?? 0;
          const last = group.days.at(-1) ?? first;
          const today = clock !== null && group.days.includes(clock.day);
          const closed = group.ranges.length === 0;

          return (
            <li
              key={first}
              aria-current={today ? "date" : undefined}
              className={cn(
                "-mx-2 flex items-start justify-between gap-4 rounded-md px-2 py-1",
                today && "bg-brand-soft font-semibold",
                closed && !today && "text-muted-foreground",
              )}
            >
              <span>{first === last ? dayName(first) : ar.store.dayRange(dayName(first), dayName(last))}</span>
              <span className="flex flex-col items-end text-end tabular-nums">
                {closed
                  ? ar.store.closedDay
                  : group.ranges.map((range) => (
                      <span key={range.from} className="whitespace-nowrap">
                        {ar.store.hoursRange(formatClock(range.from), formatClock(range.to))}
                      </span>
                    ))}
              </span>
            </li>
          );
        })}
      </ul>
    </div>
  );
}
