"use client";

import { ChevronLeftIcon, MessageCircleIcon, PhoneIcon, ReceiptTextIcon, TruckIcon } from "lucide-react";
import Link from "next/link";
import type { ReactNode } from "react";

import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import type { Category, TenantSettings } from "@/lib/api/types";
import { useCustomerSession } from "@/lib/auth/session";
import { useTenant } from "@/lib/auth/tenant";
import { ar } from "@/lib/copy/ar";
import { formatMoney } from "@/lib/format/money";
import { whatsappLink } from "@/lib/format/phone";
import { useCategories, useCategoryProducts, useFeaturedProducts } from "@/lib/store/catalog";

import { CategoryGrid } from "./CategoryGrid";
import { OrderSheet } from "./OrderSheet";
import { ProductGrid, ProductGridSkeleton } from "./ProductGrid";

/** Top categories that get their own row of products on the home page. */
const CATEGORY_ROWS = 2;

/**
 * Home (D67): a brand band with the store's name, description, ordering facts and the order sheet; then the
 * categories, the newest products and a row per top category; for guests, how ordering works. With no categories
 * yet, a contact card instead (D41).
 */
export function StoreHome() {
  const { data: tenant } = useTenant();
  const { data: customer } = useCustomerSession();
  const categories = useCategories();
  const featured = useFeaturedProducts();
  const empty = categories.data !== undefined && categories.data.length === 0;

  return (
    <main className="flex flex-1 flex-col">
      <Hero tenant={tenant} total={featured.data?.meta.total} />

      <div className="mx-auto flex w-full max-w-6xl flex-col gap-10 px-4 py-8 md:gap-12 md:py-10">
        {empty ? (
          <section className="bg-card flex flex-col items-center gap-4 rounded-2xl border p-8 text-center">
            <h2 className="font-display text-2xl font-bold">{ar.store.emptyStoreTitle}</h2>
            <p className="text-muted-foreground">{ar.store.emptyStoreBody}</p>
            {tenant?.whatsapp ? (
              <Button asChild size="lg" className="h-11">
                <a href={whatsappLink(tenant.whatsapp)} rel="noopener">
                  <MessageCircleIcon />
                  {ar.store.contactWhatsapp}
                </a>
              </Button>
            ) : (
              tenant?.phone && (
                <Button asChild size="lg" className="h-11">
                  <a href={`tel:${tenant.phone}`}>
                    <PhoneIcon />
                    {ar.store.callUs}
                  </a>
                </Button>
              )
            )}
          </section>
        ) : (
          <>
            <section id="categories" className="flex scroll-mt-28 flex-col gap-4" aria-labelledby="home-categories">
              <SectionHeading id="home-categories" title={ar.store.shopByCategory} hint={ar.store.shopByCategoryHint} />
              {categories.data ? (
                <CategoryGrid categories={categories.data} />
              ) : (
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                  {Array.from({ length: 3 }, (_, index) => (
                    <Skeleton key={index} className="h-28 rounded-2xl" />
                  ))}
                </div>
              )}
            </section>

            <section className="flex flex-col gap-4" aria-labelledby="home-featured">
              <SectionHeading id="home-featured" title={ar.store.featured} hint={ar.store.featuredHint} />
              {featured.data ? (
                <ProductGrid products={featured.data.data} rail />
              ) : (
                <ProductGridSkeleton count={5} rail />
              )}
            </section>

            {categories.data?.slice(0, CATEGORY_ROWS).map((category) => (
              <CategoryRow key={category.id} category={category} />
            ))}

            {customer === null && <HowToOrder approval={tenant?.require_customer_approval === true} />}
          </>
        )}
      </div>
    </main>
  );
}

function Hero({ tenant, total }: { tenant: TenantSettings | undefined; total: number | undefined }) {
  return (
    <section className="bg-primary text-primary-foreground ledger">
      <div className="mx-auto grid w-full max-w-6xl gap-6 px-4 py-8 md:grid-cols-[1.35fr_1fr] md:items-center md:gap-12 md:py-12">
        <div className="flex flex-col items-start">
          {tenant?.address && (
            <p className="before:bg-highlight flex items-center gap-2 text-xs opacity-85 before:h-0.5 before:w-5">
              {tenant.address}
            </p>
          )}
          <h1
            className="font-display mt-2 text-[clamp(2rem,7vw,3.25rem)] leading-tight font-bold text-balance"
            data-testid="tenant-name"
          >
            {tenant?.name ?? ar.loading}
          </h1>
          {tenant?.description && <p className="mt-3 max-w-[46ch] text-base opacity-90">{tenant.description}</p>}
          <ul className="mt-5 flex flex-wrap gap-2 text-[0.8125rem]">
            {tenant?.mov.enabled && tenant.mov.amount !== null && (
              <Fact icon={<ReceiptTextIcon />}>{ar.store.minOrder(formatMoney(tenant.mov.amount))}</Fact>
            )}
            <Fact icon={<TruckIcon />}>{ar.store.delivery}</Fact>
            {total !== undefined && total > 0 && <Fact>{ar.store.productsCount(total)}</Fact>}
          </ul>
          <div className="mt-6 flex flex-wrap gap-2.5">
            <Button
              asChild
              size="lg"
              className="bg-highlight text-highlight-foreground hover:bg-highlight/90 h-12 px-5"
            >
              <Link href="/price-list">
                <ReceiptTextIcon />
                {ar.store.fullPriceList}
              </Link>
            </Button>
            {tenant?.whatsapp && (
              <Button
                asChild
                size="lg"
                variant="outline"
                className="border-primary-foreground/35 text-primary-foreground hover:bg-primary-foreground/10 hover:text-primary-foreground h-12 bg-transparent px-5"
              >
                <a href={whatsappLink(tenant.whatsapp)} rel="noopener">
                  <MessageCircleIcon />
                  {ar.store.orderOnWhatsapp}
                </a>
              </Button>
            )}
          </div>
        </div>
        <OrderSheet />
      </div>
    </section>
  );
}

function Fact({ icon, children }: { icon?: ReactNode; children: ReactNode }) {
  return (
    <li className="border-primary-foreground/15 bg-primary-foreground/10 flex items-center gap-1.5 rounded-full border px-3 py-1.5 [&_svg]:size-4">
      {icon}
      {children}
    </li>
  );
}

function SectionHeading({ id, title, hint, href }: { id: string; title: string; hint?: string; href?: string }) {
  return (
    <div className="flex items-end justify-between gap-3">
      <div>
        <h2 id={id} className="font-display text-2xl leading-tight font-semibold">
          {title}
        </h2>
        {hint && <p className="text-muted-foreground mt-0.5 text-sm">{hint}</p>}
      </div>
      {href && (
        <Link href={href} className="text-primary flex shrink-0 items-center gap-0.5 text-sm font-semibold">
          {ar.store.viewAll}
          <ChevronLeftIcon className="size-4" aria-hidden />
        </Link>
      )}
    </div>
  );
}

function CategoryRow({ category }: { category: Category }) {
  const products = useCategoryProducts(category.slug);
  const id = `home-category-${category.id}`;

  if (products.data !== undefined && products.data.data.length === 0) {
    return null;
  }

  return (
    <section className="flex flex-col gap-4" aria-labelledby={id}>
      <SectionHeading id={id} title={category.name} href={`/c/${encodeURIComponent(category.slug)}`} />
      {products.data ? <ProductGrid products={products.data.data} rail /> : <ProductGridSkeleton count={5} rail />}
    </section>
  );
}

function HowToOrder({ approval }: { approval: boolean }) {
  return (
    <section className="flex flex-col gap-4" aria-labelledby="home-how">
      <SectionHeading id="home-how" title={ar.store.howTitle} hint={ar.store.howHint} />
      <ol className="grid grid-cols-1 gap-3 md:grid-cols-3">
        {ar.store.howSteps(approval).map((step, index) => (
          <li
            key={step.title}
            className="border-primary/30 grid grid-cols-[auto_1fr] gap-x-3.5 gap-y-1 rounded-2xl border border-dashed p-4"
          >
            <span className="bg-brand-soft text-primary font-display row-span-2 grid size-10 place-items-center rounded-full text-lg font-bold">
              {index + 1}
            </span>
            <h3 className="font-bold">{step.title}</h3>
            <p className="text-muted-foreground text-sm">{step.body}</p>
          </li>
        ))}
      </ol>
    </section>
  );
}
