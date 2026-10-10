"use client";

import { FilterIcon } from "lucide-react";
import { type ReactNode, useId, useState } from "react";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { ar } from "@/lib/copy/ar";

const copy = ar.dashboard.list;

type Props = {
  /** How many of the panel's filters are set; shown on the button so a closed panel still says it is filtering. */
  active: number;
  /** Clears the list's filters; the link shows only when given. */
  onClear?: () => void;
  /** Controls that stay visible next to the button (search, view toggles). */
  leading?: ReactNode;
  /** The filters, hidden until the button opens them. */
  children: ReactNode;
};

/** A dashboard list's filters behind a filter button, closed on every visit; their values live in the URL. */
export function FilterPanel({ active, onClear, leading, children }: Props) {
  const [open, setOpen] = useState(false);
  const id = useId();

  return (
    <div className="flex flex-col gap-2">
      <div className="flex flex-wrap items-center gap-2">
        {leading}
        <Button
          variant="outline"
          aria-label={active > 0 ? copy.filtersActive(active) : undefined}
          aria-expanded={open}
          aria-controls={id}
          onClick={() => setOpen(!open)}
        >
          <FilterIcon />
          {copy.filters}
          {active > 0 && <Badge>{active}</Badge>}
        </Button>
        {onClear !== undefined && (
          <Button variant="link" onClick={onClear}>
            {copy.clearFilters}
          </Button>
        )}
      </div>
      {open && (
        <div id={id} className="flex flex-wrap items-end gap-2 rounded-md border p-3">
          {children}
        </div>
      )}
    </div>
  );
}
