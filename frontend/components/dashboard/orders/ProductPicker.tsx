"use client";

import { PlusIcon } from "lucide-react";
import { useEffect, useId, useState } from "react";

import { Button } from "@/components/ui/button";
import { Command, CommandEmpty, CommandInput, CommandItem, CommandList } from "@/components/ui/command";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import type { DashboardProduct } from "@/lib/api/types";
import { ar } from "@/lib/copy/ar";
import { useDashboardProducts } from "@/lib/dashboard/catalog";
import { formatMoney } from "@/lib/format/money";

const copy = ar.dashboard.orders.newOrderForm;

/** How long typing pauses before the search goes to the backend. */
const SEARCH_DELAY_MS = 300;

type Props = {
  onPick: (product: DashboardProduct & { sku: NonNullable<DashboardProduct["sku"]> }) => void;
};

/** BR-O11: searches the store's active products by name or SKU code (08 §6 `q`) and hands the chosen one back. */
export function ProductPicker({ onPick }: Props) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState("");
  const [term, setTerm] = useState("");
  const listId = useId();
  const products = useDashboardProducts({ status: "active", ...(term === "" ? {} : { q: term }) }, open);

  useEffect(() => {
    const timer = setTimeout(() => setTerm(query.trim()), SEARCH_DELAY_MS);

    return () => clearTimeout(timer);
  }, [query]);

  return (
    <Popover
      open={open}
      onOpenChange={(next) => {
        setOpen(next);

        if (!next) {
          setQuery("");
        }
      }}
    >
      <PopoverTrigger asChild>
        <Button type="button" variant="outline" aria-expanded={open} aria-controls={listId}>
          <PlusIcon />
          {copy.addProduct}
        </Button>
      </PopoverTrigger>
      <PopoverContent id={listId} align="start" className="w-80 max-w-[calc(100vw-2rem)] p-0">
        <Command shouldFilter={false} loop>
          <CommandInput placeholder={copy.searchProducts} value={query} onValueChange={setQuery} />
          <CommandList>
            <CommandEmpty>{products.isPending ? copy.searching : copy.noProducts}</CommandEmpty>
            {products.data?.data.map((product) =>
              product.sku === null ? null : (
                <CommandItem
                  key={product.id}
                  value={String(product.id)}
                  onSelect={() => {
                    if (product.sku !== null) {
                      onPick({ ...product, sku: product.sku });
                    }

                    setOpen(false);
                    setQuery("");
                  }}
                >
                  <span className="flex min-w-0 flex-1 flex-col">
                    <span className="truncate">{product.name}</span>
                    <bdi dir="ltr" className="text-muted-foreground truncate text-start text-xs">
                      {product.sku.code}
                    </bdi>
                  </span>
                  <span className="text-muted-foreground shrink-0 text-xs">
                    {formatMoney(product.sku.base_price ?? "0.00")}
                  </span>
                </CommandItem>
              ),
            )}
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  );
}
