import { PackageIcon, TrendingUpIcon } from "lucide-react";
import Image from "next/image";
import Link from "next/link";

import type { StoreProduct } from "@/lib/api/types";
import { ar } from "@/lib/copy/ar";
import { formatPercent } from "@/lib/format/money";
import { unitSummary } from "@/lib/store/quantity";

import { GeneratedCover } from "./GeneratedCover";
import { PriceBlock } from "./PriceBlock";
import { QuickAdd } from "./QuickAdd";

/**
 * One product in a grid or rail (D67): the photo or a generated cover with the retailer's margin as a badge,
 * name, pack summary, the price panel, and add-to-cart in place. Coming-soon and out-of-stock
 * show as a tag.
 */
export function ProductCard({ product, priority = false }: { product: StoreProduct; priority?: boolean }) {
  const href = `/p/${encodeURIComponent(product.slug)}`;
  const comingSoon = product.sku?.coming_soon === true;
  const outOfStock = !comingSoon && product.sku?.in_stock === false;
  const pack = product.sku === null ? "" : unitSummary(product.sku);
  const margin = product.prices_hidden === true ? null : (product.sku?.discount_percent ?? null);

  return (
    <article className="group bg-card hover:border-primary/30 hover:shadow-card flex h-full flex-col overflow-hidden rounded-2xl border transition-[box-shadow,border-color,translate] duration-200 motion-safe:hover:-translate-y-0.5">
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
        {margin && (
          <span className="bg-highlight text-highlight-foreground shadow-card absolute start-5 top-5 inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-bold">
            <TrendingUpIcon className="size-3.5" aria-hidden />
            {ar.product.margin(formatPercent(margin))}
          </span>
        )}
        {(comingSoon || outOfStock) && (
          <span
            className={
              comingSoon
                ? "bg-highlight text-highlight-foreground absolute end-5 top-5 rounded-md px-2 py-0.5 text-xs font-bold"
                : "bg-card text-foreground shadow-card absolute end-5 top-5 rounded-md px-2 py-0.5 text-xs font-bold"
            }
          >
            {comingSoon ? ar.catalog.comingSoon : ar.catalog.outOfStock}
          </span>
        )}
      </Link>
      <div className="flex flex-1 flex-col gap-1.5 p-3 sm:p-3.5">
        <h3 className="line-clamp-2 min-h-[2.9em] text-[0.9375rem] leading-[1.45] font-bold">
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
        <div className="bg-brand-soft mt-1 rounded-xl px-2 py-2 sm:px-3 sm:py-2.5">
          <PriceBlock
            sku={product.sku}
            pricesHidden={product.prices_hidden === true}
            kind={product.category?.kind ?? null}
            loginNext={href}
            showMargin={false}
          />
        </div>
        <div className="mt-auto pt-2">
          <QuickAdd product={product} />
        </div>
      </div>
    </article>
  );
}
