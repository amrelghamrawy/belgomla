"use client";

import { PlusIcon } from "lucide-react";
import { useEffect, useId, useState } from "react";

import { Button } from "@/components/ui/button";
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from "@/components/ui/command";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import { ar } from "@/lib/copy/ar";
import { type ComponentDraft, componentFrom, pickableUnits } from "@/lib/dashboard/bundles";
import { useDashboardProducts } from "@/lib/dashboard/catalog";
import { formatMoney } from "@/lib/format/money";

const copy = ar.dashboard.bundles;

/** How long typing pauses before the search goes to the backend. */
const SEARCH_DELAY_MS = 300;

type Props = {
  /** Units already in the bundle: shown disabled (a unit goes in once). */
  picked: readonly number[];
  disabled?: boolean;
  onPick: (component: ComponentDraft) => void;
};

/**
 * BR-P15: searches the store's active products by name or code (like the new-order picker) and lists, under each
 * product, every unit of its active SKUs (variants and selling units, D91/D92) with its base price; bundles are not
 * offered (one level only, D93).
 */
export function BundleComponentPicker({ picked, disabled = false, onPick }: Props) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState("");
  const [term, setTerm] = useState("");
  const listId = useId();
  const products = useDashboardProducts({ status: "active", ...(term === "" ? {} : { q: term }) }, open);
  const groups = (products.data?.data ?? [])
    .map((product) => ({ product, units: pickableUnits(product) }))
    .filter((group) => group.units.length > 0);

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
        <Button type="button" variant="outline" disabled={disabled} aria-expanded={open} aria-controls={listId}>
          <PlusIcon />
          {copy.addComponent}
        </Button>
      </PopoverTrigger>
      <PopoverContent id={listId} align="start" className="w-96 max-w-[calc(100vw-2rem)] p-0">
        <Command shouldFilter={false} loop>
          <CommandInput placeholder={copy.searchComponents} value={query} onValueChange={setQuery} />
          <CommandList>
            <CommandEmpty>{products.isPending ? copy.searching : copy.noComponents}</CommandEmpty>
            {groups.map(({ product, units }) => (
              <CommandGroup key={product.id} heading={product.name}>
                {units.map((option) => {
                  const details = [option.sku.label, option.unit.unit?.name].filter(Boolean).join(" · ");

                  return (
                    <CommandItem
                      key={option.unit.id}
                      value={String(option.unit.id)}
                      disabled={picked.includes(option.unit.id)}
                      onSelect={() => {
                        onPick(componentFrom(product, option));
                        setOpen(false);
                        setQuery("");
                      }}
                    >
                      <span className="flex min-w-0 flex-1 flex-col">
                        <span className="truncate">{details === "" ? product.name : details}</span>
                        <bdi dir="ltr" className="text-muted-foreground truncate text-start text-xs">
                          {option.sku.code}
                          {option.unit.conversion_factor > 1 ? ` ×${option.unit.conversion_factor}` : ""}
                        </bdi>
                      </span>
                      <span className="text-muted-foreground shrink-0 text-xs">
                        {formatMoney(option.unit.base_price)}
                      </span>
                    </CommandItem>
                  );
                })}
              </CommandGroup>
            ))}
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  );
}
