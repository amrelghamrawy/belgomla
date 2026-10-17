"use client";

import { useRef } from "react";

import { Skeleton } from "@/components/ui/skeleton";
import { useAutoScroll } from "@/hooks/use-auto-scroll";
import type { StoreProduct } from "@/lib/api/types";
import { cn } from "@/lib/utils";

import { ProductCard } from "./ProductCard";

const GRID = "grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5";

/** A horizontal, snap-scrolling row: two cards on a phone, five on a wide screen (D67). */
const RAIL =
  "grid snap-x snap-mandatory auto-cols-[46%] grid-flow-col gap-3 overflow-x-auto pb-2 sm:auto-cols-[calc((100%-2.25rem)/4)] lg:auto-cols-[calc((100%-3rem)/5)] [&>li]:snap-start";

/** The first images load eagerly: on a phone they are the largest paint. A rail turns by itself. */
export function ProductGrid({ products, rail = false }: { products: readonly StoreProduct[]; rail?: boolean }) {
  const list = useRef<HTMLUListElement>(null);
  useAutoScroll(list, rail);

  return (
    <ul ref={list} className={rail ? RAIL : GRID}>
      {products.map((product, index) => (
        <li key={product.id} className="min-w-0">
          <ProductCard product={product} priority={index < 2} />
        </li>
      ))}
    </ul>
  );
}

/** Placeholders with the cards' exact shape, so nothing jumps when products arrive. */
export function ProductGridSkeleton({ count = 8, rail = false }: { count?: number; rail?: boolean }) {
  return (
    <ul className={cn(rail ? RAIL : GRID, "overflow-hidden")} aria-hidden>
      {Array.from({ length: count }, (_, index) => (
        <li key={index} className="bg-card flex flex-col overflow-hidden rounded-2xl border">
          <div className="bg-brand-soft px-3 pt-3">
            <Skeleton className="aspect-[3/4] rounded-none rounded-t-sm" />
          </div>
          <div className="flex flex-col gap-2 p-3">
            <Skeleton className="h-3 w-1/2" />
            <Skeleton className="h-10" />
            <Skeleton className="h-16" />
            <Skeleton className="h-11" />
          </div>
        </li>
      ))}
    </ul>
  );
}
