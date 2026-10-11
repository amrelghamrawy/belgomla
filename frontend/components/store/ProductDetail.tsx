"use client";

import Link from "next/link";

import { StatusPage } from "@/components/shared/StatusPage";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { isApiError } from "@/lib/api/errors";
import type { StoreProductDetail, StoreSku } from "@/lib/api/types";
import { ar, messageFor } from "@/lib/copy/ar";
import { formatMoney } from "@/lib/format/money";
import { useProduct } from "@/lib/store/catalog";
import { useRouteParam } from "@/lib/store/route";

import { AddToCartBar } from "./AddToCartBar";
import { GeneratedCover } from "./GeneratedCover";
import { PriceBlock } from "./PriceBlock";
import { ProductCard } from "./ProductCard";
import { ProductGallery } from "./ProductGallery";

/** `/p/[slug]`: gallery, prices and tiers, ordering facts, custom fields and the other editions (BR-P10). */
export function ProductDetail() {
  const slug = useRouteParam("slug");
  const { data: product, error } = useProduct(slug);

  if (isApiError(error, "not_found")) {
    return (
      <StatusPage
        title={ar.pages.notFoundTitle}
        body={ar.pages.notFoundBody}
        action={
          <Button asChild size="lg" className="h-11 px-6">
            <Link href="/">{ar.pages.backHome}</Link>
          </Button>
        }
      />
    );
  }

  if (error) {
    return <StatusPage title={ar.pages.errorTitle} body={messageFor(error)} />;
  }

  if (product === undefined) {
    return <ProductDetailSkeleton />;
  }

  return (
    <main className="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-8 px-4 pt-4 pb-40 md:pb-8">
      <div className="grid gap-6 md:grid-cols-2">
        <ProductGallery
          images={product.gallery}
          name={product.name}
          fallback={<GeneratedCover product={product} className="rounded-sm" />}
        />
        <div className="flex flex-col gap-4">
          <div className="flex flex-col gap-2">
            {product.category && <p className="text-muted-foreground text-sm">{product.category.name}</p>}
            <h1 className="font-display text-2xl leading-9 font-bold">{product.name}</h1>
            {product.sku?.coming_soon && <Badge className="w-fit">{ar.catalog.comingSoon}</Badge>}
          </div>
          <PriceBlock
            sku={product.sku}
            pricesHidden={product.prices_hidden === true}
            kind={product.category?.kind ?? null}
            size="lg"
            loginNext={`/p/${encodeURIComponent(slug)}`}
          />
          {product.sku && <OrderingFacts sku={product.sku} />}
          {product.sku?.tiers && product.sku.tiers.length > 0 && <TiersTable sku={product.sku} />}
          <AddToCartBar product={product} />
          <CustomFields product={product} />
          {product.description && (
            <p className="text-muted-foreground leading-7 whitespace-pre-line">{product.description}</p>
          )}
        </div>
      </div>
      {product.related.length > 0 && (
        <section className="flex flex-col gap-3" aria-labelledby="related-editions">
          <h2 id="related-editions" className="text-lg font-bold">
            {ar.product.related}
          </h2>
          <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
            {product.related.map((relation) => (
              <li key={`${relation.type}-${relation.product.id}`} className="flex flex-col gap-1">
                <span className="text-muted-foreground text-xs">{ar.product.relationTypes[relation.type]}</span>
                <ProductCard product={relation.product} />
              </li>
            ))}
          </ul>
        </section>
      )}
    </main>
  );
}

function OrderingFacts({ sku }: { sku: StoreSku }) {
  const unit = sku.unit ?? "";

  return (
    <dl className="grid grid-cols-2 gap-x-4 gap-y-2 rounded-xl border p-3 text-sm">
      <dt className="text-muted-foreground">{ar.product.code}</dt>
      <dd>
        <bdi>{sku.code}</bdi>
      </dd>
      <dt className="text-muted-foreground">{ar.product.unit}</dt>
      <dd>
        {unit}
        {sku.conversion_factor > 1 && (
          <span className="text-muted-foreground"> ({ar.product.conversion(unit, sku.conversion_factor)})</span>
        )}
      </dd>
      <dt className="text-muted-foreground">{ar.product.moq}</dt>
      <dd>
        {sku.moq} {unit}
      </dd>
    </dl>
  );
}

function TiersTable({ sku }: { sku: StoreSku }) {
  const unit = sku.unit ?? "";

  return (
    <table className="w-full overflow-hidden rounded-xl border text-sm">
      <caption className="mb-2 text-start font-bold">{ar.product.tiers}</caption>
      <thead className="bg-muted/60">
        <tr>
          <th scope="col" className="p-2 text-start font-medium">
            {ar.product.quantity}
          </th>
          <th scope="col" className="p-2 text-start font-medium">
            {ar.product.tierPrice}
          </th>
        </tr>
      </thead>
      <tbody>
        {sku.tiers?.map((tier) => (
          <tr key={tier.min_quantity} className="border-t">
            <td className="p-2">{ar.product.tierFrom(tier.min_quantity, unit)}</td>
            <td className="p-2 font-medium">{tier.unit_price === null ? "—" : formatMoney(tier.unit_price)}</td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}

function CustomFields({ product }: { product: StoreProductDetail }) {
  if (product.custom_fields.length === 0) {
    return null;
  }

  return (
    <section aria-labelledby="product-details" className="flex flex-col gap-2">
      <h2 id="product-details" className="font-bold">
        {ar.product.details}
      </h2>
      <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
        {product.custom_fields.map((field) => (
          <div key={field.key} className="contents">
            <dt className="text-muted-foreground">{field.label}</dt>
            <dd>{field.value}</dd>
          </div>
        ))}
      </dl>
    </section>
  );
}

function ProductDetailSkeleton() {
  return (
    <main className="mx-auto grid w-full max-w-6xl flex-1 gap-6 px-4 py-4 md:grid-cols-2" aria-busy>
      <Skeleton className="aspect-[3/4] w-full rounded-xl md:max-w-md" />
      <div className="flex flex-col gap-4">
        <Skeleton className="h-8 w-3/4" />
        <Skeleton className="h-16 w-1/2" />
        <Skeleton className="h-24" />
      </div>
    </main>
  );
}
