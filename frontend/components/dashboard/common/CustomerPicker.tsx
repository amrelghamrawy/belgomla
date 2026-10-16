"use client";

import { ChevronDownIcon, XIcon } from "lucide-react";
import { useEffect, useId, useState } from "react";

import { Command, CommandEmpty, CommandInput, CommandItem, CommandList } from "@/components/ui/command";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import { ar } from "@/lib/copy/ar";
import { useCustomer, useCustomers } from "@/lib/dashboard/customers";
import { formatPhone } from "@/lib/format/phone";

const copy = ar.dashboard.customerPicker;

/** How long typing pauses before the search goes to the backend. */
const SEARCH_DELAY_MS = 300;

type Props = {
  /** The customer id as the URL holds it ("" or undefined for none). */
  value: string | undefined;
  onChange: (value: string | null) => void;
};

/** A searchable customer combobox for list filters: searches by name or phone on the backend (08 §8 `q`). */
export function CustomerPicker({ value, onChange }: Props) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState("");
  const [term, setTerm] = useState("");
  const listId = useId();
  const id = Number(value);
  const selected = useCustomer(id, open || (Number.isInteger(id) && id > 0));
  const customers = useCustomers(term === "" ? {} : { q: term }, open);

  useEffect(() => {
    const timer = setTimeout(() => setTerm(query.trim()), SEARCH_DELAY_MS);

    return () => clearTimeout(timer);
  }, [query]);

  const choose = (next: string | null) => {
    onChange(next);
    setOpen(false);
    setQuery("");
  };

  const label = value === undefined || value === "" ? undefined : (selected.data?.name ?? copy.customerNumber(value));

  return (
    <div className="flex items-center gap-1">
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
          <button
            type="button"
            role="combobox"
            aria-label={copy.label}
            aria-expanded={open}
            aria-controls={listId}
            className="border-input focus-visible:border-ring focus-visible:ring-ring/50 dark:bg-input/30 flex h-8 w-48 min-w-0 items-center gap-2 rounded-lg border bg-transparent px-2.5 text-sm transition-colors outline-none focus-visible:ring-3"
          >
            <span
              className={
                label === undefined ? "text-muted-foreground flex-1 truncate text-start" : "flex-1 truncate text-start"
              }
            >
              {label ?? copy.all}
            </span>
            <ChevronDownIcon className="text-muted-foreground size-4 shrink-0" />
          </button>
        </PopoverTrigger>
        <PopoverContent id={listId} align="start" className="w-(--radix-popover-trigger-width) min-w-64 p-0">
          <Command shouldFilter={false} loop>
            <CommandInput placeholder={copy.search} value={query} onValueChange={setQuery} />
            <CommandList>
              <CommandEmpty>{customers.isPending ? copy.loading : copy.noMatch}</CommandEmpty>
              {customers.data?.data.map((customer) => (
                <CommandItem
                  key={customer.id}
                  value={String(customer.id)}
                  data-checked={String(customer.id) === value}
                  onSelect={choose}
                >
                  <span className="flex min-w-0 flex-col">
                    <span className="truncate">{customer.name}</span>
                    <bdi dir="ltr" className="text-muted-foreground truncate text-start text-xs">
                      {formatPhone(customer.phone)}
                    </bdi>
                  </span>
                </CommandItem>
              ))}
            </CommandList>
          </Command>
        </PopoverContent>
      </Popover>
      {label !== undefined && (
        <button
          type="button"
          aria-label={copy.clear}
          onClick={() => choose(null)}
          className="text-muted-foreground hover:text-foreground flex size-8 items-center justify-center rounded-lg"
        >
          <XIcon className="size-4" />
        </button>
      )}
    </div>
  );
}
