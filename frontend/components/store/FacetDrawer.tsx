"use client";

import { SlidersHorizontalIcon } from "lucide-react";
import { useState } from "react";

import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { RadioGroup, RadioGroupItem } from "@/components/ui/radio-group";
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

type FacetDrawerProps = {
  facets: readonly Facet[];
  selection: FacetSelection;
  onChange: (selection: FacetSelection) => void;
  /** Products matching the current selection, for the "show N products" button. */
  total: number | undefined;
};

const ALL = "";

/**
 * The filter drawer (BR-P08): one group per facet in the merchant's order (D18), each option with its count.
 * A facet takes one value (`f[key]=id`) and facets combine with AND; every change applies at once, so the URL
 * and the grid behind the drawer stay in sync. Radix gives the focus trap, Escape and arrow-key navigation.
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
        <div className="flex flex-1 flex-col gap-6 overflow-y-auto p-4">
          {facets.map((facet) => (
            <fieldset key={facet.key} className="flex flex-col gap-3">
              <legend className="mb-2 font-bold">{facet.label}</legend>
              <RadioGroup
                value={selection[facet.key] ?? ALL}
                onValueChange={(value) => onChange(toggleFacet(selection, facet.key, value === ALL ? null : value))}
                className="gap-0"
              >
                <FacetOptionRow id={`${facet.key}-all`} value={ALL} label={ar.orders.all} />
                {facet.options.map((option) => (
                  <FacetOptionRow
                    key={String(option.id)}
                    id={`${facet.key}-${option.id}`}
                    value={String(option.id)}
                    label={option.label}
                    count={option.count}
                  />
                ))}
              </RadioGroup>
            </fieldset>
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

function FacetOptionRow({ id, value, label, count }: { id: string; value: string; label: string; count?: number }) {
  return (
    <div className="flex min-h-11 items-center gap-3">
      <RadioGroupItem id={id} value={value} />
      <Label htmlFor={id} className="flex flex-1 cursor-pointer items-center justify-between py-3 font-normal">
        <span>{label}</span>
        {count !== undefined && <span className="text-muted-foreground text-xs tabular-nums">{count}</span>}
      </Label>
    </div>
  );
}
