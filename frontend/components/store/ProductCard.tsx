import { PackageIcon } from "lucide-react";
import Image from "next/image";
import Link from "next/link";

import type { StoreProduct } from "@/lib/api/types";
import { ar } from "@/lib/copy/ar";
import { unitSummary } from "@/lib/store/quantity";

import { GeneratedCover } from "./GeneratedCover";
import { PriceBlock } from "./PriceBlock";
import { QuickAdd } from "./QuickAdd";

/**
 * One product in a grid or rail (D67): the photo or a generated cover, publisher and code, name, pack summary,
 * price with the retailer's margin, and add-to-cart in place. Coming-soon and out-of-stock show as a tag.
 */
export function ProductCard({ product, priority = false }: { product: StoreProduct; priority?: boolean }) {
  const href = `/p/${encodeURIComponent(product.slug)}`;
  const comingSoon = product.sku?.coming_soon === true;
  const outOfStock = !comingSoon && product.sku?.in_stock === false;
  const publisher = product.custom_fields.find((field) => field.key === "publisher")?.value ?? product.category?.name;
  const pack = product.sku === null ? "" : unitSummary(product.sku);

  return (
    <article className="group bg-card hover:border-primary/30 hover:shadow-card flex h-full flex-col overflow-hidden rounded-2xl border transition-[box-shadow,border-color]">
      <Link href={href} className="bg-brand-soft relative block px-3 pt-3" tabIndex={-1} aria-hidden>
        {product.main_image_url ? (
          <span className="relative block aspect-[3/4] overflow-hidden rounded-t-sm">
            <Image
              src={product.main_image_url}
              alt=""
              fill
              sizes="(min-width: 1024px) 220px, (min-width: 640px) 33vw, 50vw"
              priority={priority}
              className="object-cover transition-transform duration-300 group-hover:scale-[1.03]"
            />
          </span>
        ) : (
          <GeneratedCover product={product} />
        )}
        {(comingSoon || outOfStock) && (
          <span
            className={
              comingSoon
                ? "bg-highlight text-highlight-foreground absolute start-5 top-5 rounded-md px-2 py-0.5 text-xs font-bold"
                : "bg-card text-foreground shadow-card absolute start-5 top-5 rounded-md px-2 py-0.5 text-xs font-bold"
            }
          >
            {comingSoon ? ar.catalog.comingSoon : ar.catalog.outOfStock}
          </span>
        )}
      </Link>
      <div className="flex flex-1 flex-col gap-1.5 p-3 sm:p-3.5">
        <p className="text-muted-foreground truncate text-xs">
          {publisher}
          {publisher && product.sku && " · "}
          {product.sku && <bdi className="tabular-nums">{product.sku.code}</bdi>}
        </p>
        <h3 className="line-clamp-2 min-h-[2.9em] text-sm leading-[1.45] font-semibold">
          <Link href={href} className="hover:text-primary">
            {product.name}
          </Link>
        </h3>
        {comingSoon && <span className="sr-only">{ar.catalog.comingSoon}</span>}
        {pack && (
          <p className="text-muted-foreground flex items-center gap-1.5 text-xs">
            <PackageIcon className="size-3.5 shrink-0" aria-hidden />
            {pack}
          </p>
        )}
        <PriceBlock sku={product.sku} pricesHidden={product.prices_hidden === true} loginNext={href} />
        <div className="mt-auto pt-2">
          <QuickAdd product={product} />
        </div>
      </div>
    </article>
  );
}
