import { Skeleton } from "@/components/ui/skeleton";
import type { StoreProduct } from "@/lib/api/types";

import { ProductCard } from "./ProductCard";

const GRID = "grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5";

/** The first images load eagerly: on a phone they are the largest paint. */
export function ProductGrid({ products }: { products: readonly StoreProduct[] }) {
  return (
    <ul className={GRID}>
      {products.map((product, index) => (
        <li key={product.id}>
          <ProductCard product={product} priority={index < 2} />
        </li>
      ))}
    </ul>
  );
}

/** Placeholders with the cards' exact shape, so nothing jumps when products arrive. */
export function ProductGridSkeleton({ count = 8 }: { count?: number }) {
  return (
    <ul className={GRID} aria-hidden>
      {Array.from({ length: count }, (_, index) => (
        <li key={index} className="flex flex-col overflow-hidden rounded-xl border">
          <Skeleton className="aspect-[3/4] rounded-none" />
          <div className="flex flex-col gap-2 p-3">
            <Skeleton className="h-10" />
            <Skeleton className="h-11" />
          </div>
        </li>
      ))}
    </ul>
  );
}
