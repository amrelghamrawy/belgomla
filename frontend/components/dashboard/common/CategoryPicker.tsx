"use client";

import { ChevronDownIcon } from "lucide-react";
import { type Ref, useId, useState } from "react";

import { Command, CommandEmpty, CommandInput, CommandItem, CommandList } from "@/components/ui/command";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import type { DashboardCategory } from "@/lib/api/types";
import { ar } from "@/lib/copy/ar";
import { flatten, searchCategories } from "@/lib/dashboard/category-tree";
import { cn } from "@/lib/utils";

const copy = ar.dashboard.productForm;

type Props = {
  id?: string;
  /** The category id as the form holds it ("" for none). */
  value: string;
  onChange: (value: string) => void;
  onBlur?: () => void;
  /** The pickable tree (usually active only). */
  categories: readonly DashboardCategory[];
  /** The whole tree, to still name a current category that is no longer pickable (archived). */
  allCategories?: readonly DashboardCategory[];
  invalid?: boolean;
  ref?: Ref<HTMLButtonElement>;
};

/** A searchable category combobox: the tree indented when idle, matches with their parent path while searching. */
export function CategoryPicker({
  id,
  value,
  onChange,
  onBlur,
  categories,
  allCategories = categories,
  invalid = false,
  ref,
}: Props) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState("");
  const listId = useId();
  const rows = flatten(categories);
  const matches = searchCategories(rows, query);
  const searching = query.trim() !== "";
  const selected = flatten(allCategories).find(({ node }) => String(node.id) === value);

  const choose = (next: string) => {
    onChange(next);
    setOpen(false);
    setQuery("");
  };

  return (
    <Popover
      open={open}
      onOpenChange={(next) => {
        setOpen(next);

        if (!next) {
          setQuery("");
          onBlur?.();
        }
      }}
    >
      <PopoverTrigger asChild>
        <button
          ref={ref}
          id={id}
          type="button"
          role="combobox"
          aria-expanded={open}
          aria-controls={listId}
          aria-invalid={invalid}
          className="border-input focus-visible:border-ring focus-visible:ring-ring/50 aria-invalid:border-destructive aria-invalid:ring-destructive/20 dark:bg-input/30 flex h-8 w-full min-w-0 items-center gap-2 rounded-lg border bg-transparent px-2.5 text-sm transition-colors outline-none focus-visible:ring-3 aria-invalid:ring-3"
        >
          {selected === undefined ? (
            <span className="text-muted-foreground flex-1 truncate text-start">{copy.chooseCategory}</span>
          ) : (
            <span className="flex min-w-0 flex-1 items-center gap-1 text-start">
              {selected.path.length > 0 && (
                <span className="text-muted-foreground truncate">{selected.path.join(" ‹ ")} ‹</span>
              )}
              <span className="shrink-0 truncate font-medium">{selected.node.name}</span>
            </span>
          )}
          <ChevronDownIcon className="text-muted-foreground size-4 shrink-0" />
        </button>
      </PopoverTrigger>
      <PopoverContent id={listId} align="start" className="w-(--radix-popover-trigger-width) min-w-64 p-0">
        <Command shouldFilter={false} loop>
          <CommandInput placeholder={copy.searchCategory} value={query} onValueChange={setQuery} />
          <CommandList>
            <CommandEmpty>{copy.noCategoryMatch}</CommandEmpty>
            {matches.map(({ node, depth, path }) => (
              <CommandItem
                key={node.id}
                value={String(node.id)}
                data-checked={String(node.id) === value}
                onSelect={choose}
                style={searching ? undefined : { paddingInlineStart: `${0.5 + depth * 1.25}rem` }}
                className={cn(!searching && depth === 0 && "font-medium")}
              >
                {!searching && depth > 0 && (
                  <span aria-hidden className="bg-muted-foreground/40 size-1 shrink-0 rounded-full" />
                )}
                <span className="flex min-w-0 flex-col">
                  <span className="truncate">{node.name}</span>
                  {searching && path.length > 0 && (
                    <span className="text-muted-foreground truncate text-xs">{path.join(" ‹ ")}</span>
                  )}
                </span>
              </CommandItem>
            ))}
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  );
}
