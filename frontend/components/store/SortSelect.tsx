"use client";

import { ar } from "@/lib/copy/ar";
import { SORTS, type Sort } from "@/lib/store/listing";

/** A native select: the phone's own picker is the most usable one on mobile. */
export function SortSelect({ value, onChange }: { value: Sort | undefined; onChange: (sort: Sort) => void }) {
  return (
    <label className="flex items-center gap-2 text-sm">
      <span className="text-muted-foreground shrink-0">{ar.catalog.sort}</span>
      <select
        value={value ?? "-created_at"}
        onChange={(event) => onChange(event.target.value as Sort)}
        className="border-input bg-background focus-visible:ring-ring/50 h-11 rounded-lg border px-3 outline-none focus-visible:ring-3"
      >
        {SORTS.map((sort) => (
          <option key={sort} value={sort}>
            {ar.catalog.sorts[sort]}
          </option>
        ))}
      </select>
    </label>
  );
}
