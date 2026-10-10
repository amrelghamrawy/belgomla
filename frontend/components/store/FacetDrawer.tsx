"use client";

import { ChevronDownIcon, SearchIcon, SlidersHorizontalIcon } from "lucide-react";
import { RadioGroup as RadioGroupPrimitive } from "radix-ui";
import { useId, useState } from "react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetFooter,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
} from "@/components/ui/sheet";
import type { Facet } from "@/lib/api/types";
import { ar } from "@/lib/copy/ar";
import { toggleFacet, type FacetSelection } from "@/lib/store/listing";
import { cn } from "@/lib/utils";

type FacetDrawerProps = {
  facets: readonly Facet[];
  selection: FacetSelection;
  onChange: (selection: FacetSelection) => void;
  /** Products matching the current selection, for the "show N products" button. */
  total: number | undefined;
};

const ALL = "";
/** Options a group shows before "show all"; the selected option is always shown. */
const VISIBLE_OPTIONS = 8;
/** A group with more options than this gets a search box. */
const SEARCH_FROM = 12;
/** Groups open when the drawer opens; the rest start collapsed unless they hold a selection. */
const OPEN_GROUPS = 2;

/**
 * The filter drawer (BR-P08): one group per facet in the merchant's order (D18), each option a chip with its count.
 * A facet takes one value (`f[key]=id`) and facets combine with AND; every change applies at once, so the URL
 * and the grid behind the drawer stay in sync. The drawer stays short however big the catalog: a group collapses
 * to its current choice, shows its first options until "show all", and gets a search box when it has many.
 * Radix gives the focus trap, Escape and arrow-key navigation.
 */
export function FacetDrawer({ facets, selection, onChange, total }: FacetDrawerProps) {
  const [open, setOpen] = useState(false);
  const active = Object.keys(selection).length;

  return (
    <Sheet open={open} onOpenChange={setOpen}>
      <SheetTrigger asChild>
        <Button variant="outline" className="h-11">
          <SlidersHorizontalIcon />
          {active > 0 ? ar.catalog.activeFilters(active) : ar.catalog.filters}
        </Button>
      </SheetTrigger>
      <SheetContent side="start" className="w-[88vw] max-w-sm gap-0 p-0">
        <SheetHeader className="border-b">
          <SheetTitle>{ar.catalog.filtersTitle}</SheetTitle>
          <SheetDescription className="sr-only">{ar.catalog.filtersTitle}</SheetDescription>
        </SheetHeader>
        <div className="flex flex-1 flex-col divide-y overflow-y-auto overscroll-contain">
          {facets.map((facet, index) => (
            <FacetGroup
              key={facet.key}
              facet={facet}
              value={selection[facet.key]}
              defaultOpen={index < OPEN_GROUPS || selection[facet.key] !== undefined}
              onSelect={(value) => onChange(toggleFacet(selection, facet.key, value))}
            />
          ))}
        </div>
        <SheetFooter className="flex-row border-t">
          <Button variant="ghost" className="h-11 flex-1" disabled={active === 0} onClick={() => onChange({})}>
            {ar.catalog.clearFilters}
          </Button>
          <Button className="h-11 flex-[2]" onClick={() => setOpen(false)}>
            {total === undefined ? ar.close : ar.catalog.showResults(total)}
          </Button>
        </SheetFooter>
      </SheetContent>
    </Sheet>
  );
}

type FacetGroupProps = {
  facet: Facet;
  value: string | undefined;
  defaultOpen: boolean;
  onSelect: (value: string | null) => void;
};

function FacetGroup({ facet, value, defaultOpen, onSelect }: FacetGroupProps) {
  const [expanded, setExpanded] = useState(defaultOpen);
  const [showAll, setShowAll] = useState(false);
  const [query, setQuery] = useState("");
  const panelId = useId();

  const chosen = facet.options.find((option) => String(option.id) === value);
  const needle = query.trim();
  const matches = needle ? facet.options.filter((option) => option.label.includes(needle)) : facet.options;
  const capped = !needle && !showAll && matches.length > VISIBLE_OPTIONS;
  const shown = capped ? matches.filter((option, index) => index < VISIBLE_OPTIONS || option === chosen) : matches;

  return (
    <fieldset className="px-4 py-2">
      <legend className="contents">
        <button
          type="button"
          aria-expanded={expanded}
          aria-controls={panelId}
          onClick={() => setExpanded((was) => !was)}
          className="flex min-h-11 w-full items-center gap-2 text-start"
        >
          <span className="font-bold">{facet.label}</span>
          <span
            className={cn("ms-auto truncate text-sm", chosen ? "text-primary font-medium" : "text-muted-foreground")}
          >
            {chosen?.label ?? ar.orders.all}
          </span>
          <ChevronDownIcon
            aria-hidden
            className={cn("text-muted-foreground size-4 shrink-0 transition-transform", expanded && "rotate-180")}
          />
        </button>
      </legend>
      <div id={panelId} hidden={!expanded} className="flex flex-col gap-3 pb-2">
        {facet.options.length > SEARCH_FROM && (
          <div className="relative">
            <SearchIcon
              aria-hidden
              className="text-muted-foreground pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2"
            />
            <Input
              type="search"
              value={query}
              onChange={(event) => setQuery(event.target.value)}
              placeholder={ar.catalog.searchOptions(facet.label)}
              aria-label={ar.catalog.searchOptions(facet.label)}
              className="h-10 ps-9"
            />
          </div>
        )}
        <RadioGroupPrimitive.Root
          value={value ?? ALL}
          onValueChange={(next) => onSelect(next === ALL ? null : next)}
          aria-label={facet.label}
          className="flex flex-wrap gap-2"
        >
          {!needle && <FacetChip value={ALL} label={ar.orders.all} />}
          {shown.map((option) => (
            <FacetChip key={String(option.id)} value={String(option.id)} label={option.label} count={option.count} />
          ))}
        </RadioGroupPrimitive.Root>
        {needle && matches.length === 0 && (
          <p className="text-muted-foreground text-sm">{ar.catalog.noOptionMatches}</p>
        )}
        {!needle && matches.length > VISIBLE_OPTIONS && (
          <button
            type="button"
            onClick={() => setShowAll((was) => !was)}
            className="text-primary self-start py-1 text-sm font-medium hover:underline"
          >
            {showAll ? ar.catalog.showLess : ar.catalog.showAllOptions(matches.length)}
          </button>
        )}
      </div>
    </fieldset>
  );
}

function FacetChip({ value, label, count }: { value: string; label: string; count?: number }) {
  return (
    <RadioGroupPrimitive.Item
      value={value}
      className={cn(
        "border-input bg-background inline-flex h-9 max-w-full items-center gap-1.5 rounded-full border px-3 text-sm transition-colors outline-none",
        "hover:bg-brand-soft focus-visible:ring-ring/50 focus-visible:ring-3",
        "data-[state=checked]:border-primary data-[state=checked]:bg-primary data-[state=checked]:text-primary-foreground",
      )}
    >
      <span className="truncate">{label}</span>
      {count !== undefined && <span className="text-xs tabular-nums opacity-70">{count}</span>}
    </RadioGroupPrimitive.Item>
  );
}
