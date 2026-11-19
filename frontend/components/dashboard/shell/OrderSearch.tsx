"use client";

import { SearchIcon } from "lucide-react";
import { useRouter } from "next/navigation";
import { type FormEvent, useState } from "react";

import { Input } from "@/components/ui/input";
import { ar } from "@/lib/copy/ar";

const copy = ar.dashboard.nav;

/**
 * D104: the top bar's search, for the first daily job: type an order number (or part of it) and land on the
 * orders list filtered by it. An empty search does nothing.
 */
export function OrderSearch() {
  const router = useRouter();
  const [q, setQ] = useState("");

  const submit = (event: FormEvent) => {
    event.preventDefault();
    const term = q.trim();

    if (term !== "") {
      router.push(`/dashboard/orders?q=${encodeURIComponent(term)}`);
    }
  };

  return (
    <form role="search" onSubmit={submit} className="relative hidden w-56 md:block lg:w-64">
      <SearchIcon
        aria-hidden
        className="text-muted-foreground pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2"
      />
      <Input
        type="search"
        aria-label={copy.search}
        placeholder={copy.search}
        value={q}
        onChange={(event) => setQ(event.target.value)}
        className="bg-muted h-10 rounded-full border-0 ps-9! shadow-none"
      />
    </form>
  );
}
