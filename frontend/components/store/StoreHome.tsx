"use client";

import { MessageCircleIcon, PhoneIcon } from "lucide-react";
import Link from "next/link";

import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { useTenant } from "@/lib/auth/tenant";
import { ar } from "@/lib/copy/ar";
import { whatsappLink } from "@/lib/format/phone";
import { useCategories, useFeaturedProducts } from "@/lib/store/catalog";

import { CategoryGrid } from "./CategoryGrid";
import { ProductGrid, ProductGridSkeleton } from "./ProductGrid";

/** Home: the store's name, its categories and the newest products. With no categories yet, a contact card (D41). */
export function StoreHome() {
  const { data: tenant } = useTenant();
  const categories = useCategories();
  const featured = useFeaturedProducts();
  const empty = categories.data !== undefined && categories.data.length === 0;

  return (
    <main className="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-8 px-4 py-6">
      <section className="flex flex-col gap-1">
        <p className="text-muted-foreground text-sm">{ar.store.welcome}</p>
        <h1 className="text-primary text-2xl font-bold" data-testid="tenant-name">
          {tenant?.name ?? ar.loading}
        </h1>
        {tenant?.description && <p className="text-muted-foreground">{tenant.description}</p>}
      </section>

      {empty ? (
        <section className="flex flex-col items-center gap-4 rounded-xl border p-8 text-center">
          <h2 className="text-xl font-bold">{ar.store.emptyStoreTitle}</h2>
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
          <section className="flex flex-col gap-3" aria-labelledby="home-categories">
            <div className="flex items-center justify-between">
              <h2 id="home-categories" className="text-lg font-bold">
                {ar.store.categories}
              </h2>
              <Link href="/price-list" className="text-primary text-sm underline-offset-4 hover:underline">
                {ar.store.priceList}
              </Link>
            </div>
            {categories.data ? (
              <CategoryGrid categories={categories.data} />
            ) : (
              <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                {Array.from({ length: 4 }, (_, index) => (
                  <Skeleton key={index} className="h-16 rounded-xl" />
                ))}
              </div>
            )}
          </section>
          <section className="flex flex-col gap-3" aria-labelledby="home-featured">
            <h2 id="home-featured" className="text-lg font-bold">
              {ar.store.featured}
            </h2>
            {featured.data ? <ProductGrid products={featured.data.data} /> : <ProductGridSkeleton count={4} />}
          </section>
        </>
      )}
    </main>
  );
}
