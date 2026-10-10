"use client";

import { SearchIcon } from "lucide-react";
import { useState, type FormEvent } from "react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import type { Category } from "@/lib/api/types";
import { ar } from "@/lib/copy/ar";
import { categoryOptions, useFilters } from "@/lib/store/catalog";
import type { Listing } from "@/lib/store/listing";

import { FacetDrawer } from "./FacetDrawer";

type PriceListFiltersProps = {
  listing: Listing;
  categories: readonly Category[];
  /** Rows in the current list, for the drawer's "show N products" button. */
  total: number | undefined;
  onChange: (listing: Listing) => void;
};

const ALL = "";

/**
 * The price list's selection (BR-P13): search, category and the facet drawer (BR-P08). Every change goes to the
 * URL, so the shared link and the printout show the same rows. Hidden in print.
 */
export function PriceListFilters({ listing, categories, total, onChange }: PriceListFiltersProps) {
  const filters = useFilters(listing);
  const filtered = listing.category !== undefined || listing.q !== undefined || Object.keys(listing.f).length > 0;

  return (
    <div className="flex flex-wrap items-center gap-2 print:hidden" data-testid="price-list-filters">
      <SearchField
        key={listing.q ?? ""}
        defaultValue={listing.q ?? ""}
        onSearch={(q) => onChange({ ...listing, q: q === "" ? undefined : q })}
      />
      <label className="flex min-w-0 items-center gap-2 text-sm">
        <span className="text-muted-foreground shrink-0">{ar.priceList.category}</span>
        <select
          value={listing.category ?? ALL}
          // Facets differ per category, so a new category starts with none selected.
          onChange={(event) => onChange({ ...listing, category: event.target.value || undefined, f: {} })}
          className="border-input bg-background focus-visible:ring-ring/50 h-11 min-w-0 rounded-lg border px-3 outline-none focus-visible:ring-3"
        >
          <option value={ALL}>{ar.priceList.allCategories}</option>
          {categoryOptions(categories).map((option) => (
            <option key={option.slug} value={option.slug}>
              {"  ".repeat(option.depth)}
              {option.name}
            </option>
          ))}
        </select>
      </label>
      <FacetDrawer
        facets={filters.data ?? []}
        selection={listing.f}
        total={total}
        onChange={(f) => onChange({ ...listing, f })}
      />
      {filtered && (
        <Button variant="ghost" className="h-11" onClick={() => onChange({ f: {} })}>
          {ar.priceList.clearFilters}
        </Button>
      )}
    </div>
  );
}

function SearchField({ defaultValue, onSearch }: { defaultValue: string; onSearch: (q: string) => void }) {
  const [q, setQ] = useState(defaultValue);

  const submit = (event: FormEvent) => {
    event.preventDefault();
    onSearch(q.trim());
  };

  return (
    <form role="search" onSubmit={submit} className="relative flex w-full items-center sm:w-64">
      <label htmlFor="price-list-search" className="sr-only">
        {ar.priceList.search}
      </label>
      <Input
        id="price-list-search"
        type="search"
        enterKeyHint="search"
        value={q}
        onChange={(event) => setQ(event.target.value)}
        placeholder={ar.priceList.searchPlaceholder}
        className="h-11 pe-11"
      />
      <Button
        type="submit"
        variant="ghost"
        size="icon"
        className="absolute end-0.5 size-10"
        aria-label={ar.priceList.search}
      >
        <SearchIcon />
      </Button>
    </form>
  );
}
