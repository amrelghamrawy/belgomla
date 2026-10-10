"use client";

import { SearchIcon } from "lucide-react";
import { useRouter } from "next/navigation";
import { useState, type FormEvent } from "react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { ar } from "@/lib/copy/ar";
import { cn } from "@/lib/utils";

/** Store-wide search (BR-P08): name, SKU code or a facet label; opens `/search?q=`. */
export function SearchBox({ defaultValue = "", className }: { defaultValue?: string; className?: string }) {
  const router = useRouter();
  const [q, setQ] = useState(defaultValue);

  const submit = (event: FormEvent) => {
    event.preventDefault();
    const term = q.trim();

    if (term !== "") {
      router.push(`/search?q=${encodeURIComponent(term)}`);
    }
  };

  return (
    <form role="search" onSubmit={submit} className={cn("relative flex w-full items-center", className)}>
      <label htmlFor="store-search" className="sr-only">
        {ar.store.search}
      </label>
      <Input
        id="store-search"
        type="search"
        enterKeyHint="search"
        value={q}
        onChange={(event) => setQ(event.target.value)}
        placeholder={ar.store.searchPlaceholder}
        className="bg-background h-11 rounded-full ps-4 pe-12"
      />
      <Button
        type="submit"
        variant="ghost"
        size="icon"
        className="bg-primary text-primary-foreground hover:bg-primary/90 hover:text-primary-foreground absolute end-1 size-9 rounded-full"
        aria-label={ar.store.search}
      >
        <SearchIcon />
      </Button>
    </form>
  );
}
