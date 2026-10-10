"use client";

import { BellIcon } from "lucide-react";
import Link from "next/link";
import { useState } from "react";

import { Button } from "@/components/ui/button";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import { ar } from "@/lib/copy/ar";
import { useMarkNotificationsRead, useNotifications } from "@/lib/dashboard/notifications";
import { formatDateTime } from "@/lib/format/date";
import { formatMoney } from "@/lib/format/money";
import { cn } from "@/lib/utils";

const copy = ar.dashboard.notifications;

/** The bell: unread count (polled every 30 s), the newest notifications, each linking to its order. */
export function NotificationsBell() {
  const [open, setOpen] = useState(false);
  const notifications = useNotifications();
  const markRead = useMarkNotificationsRead();
  const unread = notifications.data?.unread_count ?? 0;
  const items = notifications.data?.data ?? [];

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button variant="ghost" size="icon" className="relative size-11" aria-label={copy.open(unread)}>
          <BellIcon />
          {unread > 0 && (
            <span
              data-testid="unread-count"
              className="bg-destructive absolute end-1 top-1 grid min-w-5 place-items-center rounded-full px-1 text-xs leading-5 text-white"
            >
              {unread}
            </span>
          )}
        </Button>
      </PopoverTrigger>
      <PopoverContent align="end" className="w-80 max-w-[calc(100vw-2rem)] p-0">
        <div className="flex items-center justify-between gap-2 border-b p-3">
          <p className="font-medium">{copy.title}</p>
          {unread > 0 && (
            <Button
              variant="link"
              size="sm"
              disabled={markRead.isPending}
              onClick={() => markRead.mutate({ all: true })}
            >
              {copy.markAllRead}
            </Button>
          )}
        </div>
        {items.length === 0 ? (
          <p className="text-muted-foreground p-4 text-sm">{copy.empty}</p>
        ) : (
          <ul className="max-h-96 overflow-y-auto">
            {items.map((item) => (
              <li key={item.id} className="border-b last:border-b-0">
                <Link
                  href={`/dashboard/orders/${item.details.order_number}`}
                  className={cn(
                    "hover:bg-muted flex flex-col gap-1 p-3 text-sm",
                    item.read_at === null && "bg-primary/5",
                  )}
                  onClick={() => {
                    setOpen(false);
                    if (item.read_at === null) {
                      markRead.mutate({ ids: [item.id] });
                    }
                  }}
                >
                  <span className="font-medium">{item.type_label}</span>
                  <span>{copy.orderLine(item.details.order_number, item.details.customer_name)}</span>
                  <span className="text-muted-foreground flex justify-between gap-2 text-xs">
                    <span>{formatMoney(item.details.order_total)}</span>
                    <span>{formatDateTime(item.created_at)}</span>
                  </span>
                </Link>
              </li>
            ))}
          </ul>
        )}
      </PopoverContent>
    </Popover>
  );
}
