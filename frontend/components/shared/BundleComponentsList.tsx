"use client";

import { ChevronDownIcon } from "lucide-react";
import { useId, useState } from "react";

import { Button } from "@/components/ui/button";
import type { OrderItem } from "@/lib/api/types";
import { ar } from "@/lib/copy/ar";
import { variantOf } from "@/lib/store/order-lines";
import { cn } from "@/lib/utils";

/**
 * BR-P15, D93: a bundle line of an order (store and dashboard) opens to the component lines it holds: each with its
 * quantity and unit, its variant and what was delivered of it when short. Closed by default.
 */
export function BundleComponentsList({ bundle, components }: { bundle: OrderItem; components: OrderItem[] }) {
  const [open, setOpen] = useState(false);
  const listId = useId();

  if (components.length === 0) {
    return null;
  }

  return (
    <div className="flex flex-col gap-1">
      <Button
        type="button"
        variant="link"
        size="sm"
        className="h-auto self-start px-0 text-xs"
        aria-expanded={open}
        aria-controls={listId}
        onClick={() => setOpen(!open)}
      >
        {open ? ar.orders.hideComponents : ar.orders.showComponents(components.length)}
        <ChevronDownIcon className={cn("size-3.5 transition-transform", open && "rotate-180")} aria-hidden />
      </Button>
      {open && (
        <ul
          id={listId}
          aria-label={ar.orders.components(bundle.product_name)}
          className="flex flex-col gap-1 border-s ps-3 text-xs"
          data-testid={`bundle-components-${bundle.id}`}
        >
          {components.map((component) => (
            <li key={component.id} className="flex flex-col">
              <span>
                <span className="font-medium">{component.product_name}</span>
                {variantOf(component) !== null && (
                  <span className="text-muted-foreground"> · {variantOf(component)}</span>
                )}{" "}
                × {ar.orders.quantityOf(component.quantity, component.unit)}
              </span>
              {component.delivered_quantity !== null && component.delivered_quantity < component.quantity && (
                <span className="text-destructive">
                  {ar.orders.deliveredOf(component.delivered_quantity, component.quantity, component.unit)}
                </span>
              )}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
