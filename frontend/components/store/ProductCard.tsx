import { ImageOffIcon } from "lucide-react";
import Image from "next/image";
import Link from "next/link";

import { Badge } from "@/components/ui/badge";
import type { StoreProduct } from "@/lib/api/types";
import { ar } from "@/lib/copy/ar";

import { PriceBlock } from "./PriceBlock";

/** One product in a grid: image, name, price (or the login link), and a coming-soon / out-of-stock badge. */
export function ProductCard({ product, priority = false }: { product: StoreProduct; priority?: boolean }) {
  const href = `/p/${encodeURIComponent(product.slug)}`;
  const comingSoon = product.sku?.coming_soon === true;
  const outOfStock = !comingSoon && product.sku?.in_stock === false;

  return (
    <article className="bg-card flex h-full flex-col overflow-hidden rounded-xl border">
      <Link href={href} className="bg-muted relative block aspect-[3/4]" tabIndex={-1} aria-hidden>
        {product.main_image_url ? (
          <Image
            src={product.main_image_url}
            alt=""
            fill
            sizes="(min-width: 1024px) 220px, (min-width: 640px) 33vw, 50vw"
            priority={priority}
            className="object-cover"
          />
        ) : (
          <span className="text-muted-foreground grid size-full place-items-center">
            <ImageOffIcon className="size-8" />
          </span>
        )}
        {(comingSoon || outOfStock) && (
          <Badge variant={comingSoon ? "default" : "secondary"} className="absolute start-2 top-2">
            {comingSoon ? ar.catalog.comingSoon : ar.catalog.outOfStock}
          </Badge>
        )}
      </Link>
      <div className="flex flex-1 flex-col gap-1 p-3">
        <h3 className="line-clamp-2 min-h-10 text-sm leading-5 font-medium">
          <Link href={href} className="hover:underline">
            {product.name}
          </Link>
        </h3>
        {comingSoon && <span className="sr-only">{ar.catalog.comingSoon}</span>}
        <PriceBlock sku={product.sku} pricesHidden={product.prices_hidden === true} loginNext={href} />
      </div>
    </article>
  );
}
