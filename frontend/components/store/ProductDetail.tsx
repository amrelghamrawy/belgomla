"use client";

import { HashIcon, LayersIcon, PackageIcon } from "lucide-react";
import Link from "next/link";
import type { ReactNode } from "react";

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
      <div className="grid items-start gap-6 md:grid-cols-2 lg:gap-10">
        <div className="md:sticky md:top-20">
          <ProductGallery
            images={product.gallery}
            name={product.name}
            fallback={<GeneratedCover product={product} className="rounded-sm" />}
          />
        </div>
        <div className="flex min-w-0 flex-col gap-4">
          <section className="bg-card shadow-card flex flex-col gap-5 rounded-2xl border p-4 sm:p-6">
            <div className="flex flex-col items-start gap-2">
              {product.category && (
                <span className="bg-brand-soft text-primary rounded-full px-3 py-1 text-xs font-semibold">
                  {product.category.name}
                </span>
              )}
              <h1 className="font-display text-2xl leading-snug font-bold md:text-3xl">{product.name}</h1>
              {product.sku?.coming_soon && <Badge>{ar.catalog.comingSoon}</Badge>}
            </div>
            <div className="bg-brand-soft rounded-xl p-4">
              <PriceBlock
                sku={product.sku}
                pricesHidden={product.prices_hidden === true}
                kind={product.category?.kind ?? null}
                size="lg"
                loginNext={`/p/${encodeURIComponent(slug)}`}
              />
            </div>
            {product.sku && <OrderingFacts sku={product.sku} />}
            {product.sku?.tiers && product.sku.tiers.length > 0 && <TiersTable sku={product.sku} />}
            <AddToCartBar product={product} />
          </section>
          <AboutProduct product={product} />
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

/** Unit, minimum order and code as three tiles, the facts a retailer checks before choosing a quantity. */
function OrderingFacts({ sku }: { sku: StoreSku }) {
  const unit = sku.unit ?? "";

  return (
    <dl className="grid grid-cols-3 gap-2 text-sm">
      <Fact icon={<PackageIcon />} label={ar.product.unit}>
        {unit}
        {sku.conversion_factor > 1 && (
          <span className="text-muted-foreground block text-xs font-normal">
            ({ar.product.conversion(unit, sku.conversion_factor)})
          </span>
        )}
      </Fact>
      <Fact icon={<LayersIcon />} label={ar.product.moq}>
        {sku.moq} {unit}
      </Fact>
      <Fact icon={<HashIcon />} label={ar.product.code}>
        <bdi className="tabular-nums">{sku.code}</bdi>
      </Fact>
    </dl>
  );
}

function Fact({ icon, label, children }: { icon: ReactNode; label: string; children: ReactNode }) {
  return (
    <div className="flex min-w-0 flex-col gap-1 rounded-xl border p-2.5 sm:p-3">
      <dt className="text-muted-foreground flex items-start gap-1.5 text-xs leading-4 [&_svg]:size-3.5 [&_svg]:shrink-0">
        {icon}
        <span>{label}</span>
      </dt>
      <dd className="font-semibold wrap-break-word">{children}</dd>
    </div>
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

/** The product's own fields as label/value rows, then the description. */
function AboutProduct({ product }: { product: StoreProductDetail }) {
  if (product.custom_fields.length === 0 && !product.description) {
    return null;
  }

  return (
    <section
      aria-labelledby="product-details"
      className="bg-card shadow-card flex flex-col gap-3 rounded-2xl border p-4 sm:p-6"
    >
      <h2 id="product-details" className="font-display text-lg font-bold">
        {ar.product.details}
      </h2>
      {product.custom_fields.length > 0 && (
        <dl className="divide-y text-sm">
          {product.custom_fields.map((field) => (
            <div key={field.key} className="flex items-baseline justify-between gap-4 py-2.5">
              <dt className="text-muted-foreground shrink-0">{field.label}</dt>
              <dd className="text-end font-medium">{field.value}</dd>
            </div>
          ))}
        </dl>
      )}
      {product.description && (
        <p className="text-muted-foreground border-t pt-3 leading-8 whitespace-pre-line">{product.description}</p>
      )}
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
