"use client";

import { CopyIcon } from "lucide-react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { type FormEvent, useState } from "react";
import { toast } from "sonner";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { NativeSelect } from "@/components/ui/native-select";
import { Switch } from "@/components/ui/switch";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import type { DashboardProduct, FieldDefinition, ProductStatus } from "@/lib/api/types";
import { ar, messageFor } from "@/lib/copy/ar";
import {
  bulkFilterOf,
  PRODUCT_LIST_KEYS,
  useDashboardCategories,
  useDashboardProducts,
  useDuplicateProduct,
  useFields,
} from "@/lib/dashboard/catalog";
import { activeOnly, flatten } from "@/lib/dashboard/category-tree";
import { activeFilters, hasFilters } from "@/lib/dashboard/list-query";
import { useCan } from "@/lib/dashboard/permissions";
import { useListQuery } from "@/lib/dashboard/use-list-query";
import { formatMoney } from "@/lib/format/money";

import { FilterPanel } from "../common/FilterPanel";
import { NewPromotionButton } from "../pricing/PromotionDialog";
import { Pager } from "../common/Pager";
import { EmptyBlock, ErrorBlock, LoadingBlock, PageHeader } from "../common/States";
import { BulkPricingDialog } from "./BulkPricingDialog";
import { BulkStatusDialog } from "./BulkStatusDialog";
import { ExportButtons } from "./ExportButtons";
import { GradeSetWizard } from "./GradeSetWizard";
import { ImportWizard } from "./ImportWizard";

const copy = ar.dashboard.products;
const STATUSES: ProductStatus[] = ["active", "coming_soon", "inactive"];

/**
 * The product list with the store's facets plus status and low stock, bulk actions and duplicate (BR-P12), and the
 * catalog file: export of the current filter and the import wizard (V2 Task 06, `catalog.manage`).
 */
export function ProductsPage() {
  const [query, setQuery] = useListQuery(PRODUCT_LIST_KEYS);
  const allowed = useCan();
  const products = useDashboardProducts(query);
  const categories = useDashboardCategories();
  const fields = useFields();
  const scope = bulkFilterOf(query);
  const total = products.data?.meta.total ?? 0;
  const categoryOptions = categories.data ? flatten(activeOnly(categories.data)) : [];
  const facets = (fields.data ?? []).filter((field) => field.is_filterable && field.status === "active");

  return (
    <>
      <PageHeader
        title={copy.title}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            {allowed("catalog.manage") && <ExportButtons query={query} />}
            {allowed("catalog.manage") && <ImportWizard />}
            {allowed("catalog.manage") && <GradeSetWizard query={query} categories={categoryOptions} facets={facets} />}
            {allowed("catalog.manage") && (
              <Button asChild variant="outline">
                <Link href="/dashboard/bundles/new">{ar.dashboard.bundles.add}</Link>
              </Button>
            )}
            <Button asChild>
              <Link href="/dashboard/products/new">{copy.add}</Link>
            </Button>
          </div>
        }
      />
      <Filters query={query} setQuery={setQuery} categories={categoryOptions} facets={facets} />
      <div className="flex flex-wrap items-center gap-2 rounded-md border p-2 text-sm">
        <span className="font-medium">{copy.bulk}:</span>
        {"filter" in scope ? (
          <>
            <span className="text-muted-foreground">{copy.bulkScope(total)}</span>
            <BulkStatusDialog filter={scope.filter} count={total} />
            <BulkPricingDialog filter={scope.filter} />
            {allowed("pricing.manage") && <NewPromotionButton filter={scope.filter} />}
          </>
        ) : (
          <span className="text-muted-foreground" data-testid="bulk-blocked">
            {scope.blocked === "low_stock" ? copy.bulkNoLowStock : copy.bulkNeedsFilter}
          </span>
        )}
      </div>
      {products.isError && <ErrorBlock error={products.error} onRetry={() => void products.refetch()} />}
      {products.isPending && <LoadingBlock />}
      {products.data?.data.length === 0 && <EmptyBlock>{copy.empty}</EmptyBlock>}
      {products.data !== undefined && products.data.data.length > 0 && (
        <div className="overflow-x-auto rounded-md border">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>{copy.name}</TableHead>
                <TableHead>{copy.code}</TableHead>
                <TableHead>{copy.category}</TableHead>
                <TableHead>{copy.status}</TableHead>
                <TableHead>{copy.price}</TableHead>
                <TableHead>{copy.stock}</TableHead>
                <TableHead>{ar.dashboard.list.actions}</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {products.data.data.map((product) => (
                <ProductRow key={product.id} product={product} />
              ))}
            </TableBody>
          </Table>
        </div>
      )}
      <Pager meta={products.data?.meta} onPage={(page) => setQuery({ page })} />
    </>
  );
}

function ProductRow({ product }: { product: DashboardProduct }) {
  const router = useRouter();
  const duplicate = useDuplicateProduct();
  // BR-P15, D93: a bundle has no stock of its own (no low-stock flag) and its own editor; it is not duplicated.
  const bundle = product.type === "bundle";
  const available = bundle ? undefined : product.sku?.inventory?.available;
  const low =
    product.sku !== null &&
    available !== undefined &&
    available <= (product.sku.moq ?? 0) * (product.sku.conversion_factor ?? 1);

  return (
    <TableRow data-testid={`product-${product.id}`}>
      <TableCell className="max-w-64 font-medium">
        <Link
          href={bundle ? `/dashboard/bundles/${product.id}` : `/dashboard/products/${product.id}`}
          className="hover:underline"
        >
          {product.name}
        </Link>
        {bundle && (
          <Badge variant="outline" className="ms-2" data-testid={`bundle-badge-${product.id}`}>
            {ar.dashboard.bundles.badge}
          </Badge>
        )}
      </TableCell>
      <TableCell>
        <bdi>{product.sku?.code ?? ar.dashboard.none}</bdi>
      </TableCell>
      <TableCell>{product.category?.name ?? ar.dashboard.none}</TableCell>
      <TableCell>
        <Badge variant={product.status === "active" ? "default" : "secondary"}>
          {ar.dashboard.productStatuses[product.status]}
        </Badge>
      </TableCell>
      <TableCell className="whitespace-nowrap">
        {product.sku?.base_price ? formatMoney(product.sku.base_price) : ar.dashboard.none}
      </TableCell>
      <TableCell className="whitespace-nowrap">
        {available ?? ar.dashboard.none}
        {low && (
          <Badge variant="destructive" className="ms-2">
            {copy.lowStock}
          </Badge>
        )}
      </TableCell>
      <TableCell>
        {!bundle && (
          <Button
            variant="ghost"
            size="sm"
            disabled={duplicate.isPending}
            onClick={() =>
              duplicate.mutate(product.id, {
                onSuccess: (copyOf) => {
                  toast.success(copy.duplicated);
                  router.push(`/dashboard/products/${copyOf.id}`);
                },
                onError: (error) => toast.error(messageFor(error)),
              })
            }
          >
            <CopyIcon />
            {copy.duplicate}
          </Button>
        )}
      </TableCell>
    </TableRow>
  );
}

type FiltersProps = {
  query: Record<string, string>;
  setQuery: (patch: Record<string, string | number | null>) => void;
  categories: ReturnType<typeof flatten>;
  facets: FieldDefinition[];
};

function Filters({ query, setQuery, categories, facets }: FiltersProps) {
  const [q, setQ] = useState(query.q ?? "");

  const search = (event: FormEvent) => {
    event.preventDefault();
    setQuery({ q: q.trim() });
  };

  return (
    <FilterPanel
      active={activeFilters(query, ["category", "status", "sort", "low_stock"], true)}
      onClear={
        hasFilters(query)
          ? () => {
              setQ("");
              setQuery(Object.fromEntries(Object.keys(query).map((key) => [key, null])));
            }
          : undefined
      }
      leading={
        <form onSubmit={search} className="flex gap-2" role="search">
          <Input
            aria-label={ar.dashboard.list.search}
            placeholder={ar.dashboard.list.searchPlaceholder}
            value={q}
            onChange={(event) => setQ(event.target.value)}
            className="max-w-sm"
          />
          <Button type="submit" variant="outline">
            {ar.dashboard.list.search}
          </Button>
        </form>
      }
    >
      <NativeSelect
        aria-label={copy.category}
        value={query.category ?? ""}
        onChange={(event) => setQuery({ category: event.target.value })}
      >
        <option value="">{copy.allCategories}</option>
        {categories.map(({ node, depth }) => (
          <option key={node.id} value={node.id}>
            {`${"— ".repeat(depth)}${node.name}`}
          </option>
        ))}
      </NativeSelect>
      <NativeSelect
        aria-label={copy.status}
        value={query.status ?? ""}
        onChange={(event) => setQuery({ status: event.target.value })}
      >
        <option value="">{copy.allStatuses}</option>
        {STATUSES.map((status) => (
          <option key={status} value={status}>
            {ar.dashboard.productStatuses[status]}
          </option>
        ))}
      </NativeSelect>
      {facets.map((facet) => (
        <NativeSelect
          key={facet.key}
          aria-label={facet.label}
          value={query[`f[${facet.key}]`] ?? ""}
          onChange={(event) => setQuery({ [`f[${facet.key}]`]: event.target.value })}
        >
          <option value="">{`${facet.label}: ${copy.anyValue}`}</option>
          {facet.type === "boolean" ? (
            <>
              <option value="true">{ar.dashboard.yes}</option>
              <option value="false">{ar.dashboard.no}</option>
            </>
          ) : (
            facet.options
              .filter((option) => option.status === "active")
              .map((option) => (
                <option key={option.id} value={option.id}>
                  {option.label}
                </option>
              ))
          )}
        </NativeSelect>
      ))}
      <NativeSelect
        aria-label={copy.sort}
        value={query.sort ?? "-created_at"}
        onChange={(event) => setQuery({ sort: event.target.value === "-created_at" ? null : event.target.value })}
      >
        {Object.entries(copy.sorts).map(([value, label]) => (
          <option key={value} value={value}>
            {label}
          </option>
        ))}
      </NativeSelect>
      <div className="flex items-center gap-2">
        <Switch
          id="low-stock"
          checked={query.low_stock === "1"}
          onCheckedChange={(checked) => setQuery({ low_stock: checked ? "1" : null })}
        />
        <Label htmlFor="low-stock">{copy.lowStockOnly}</Label>
      </div>
    </FilterPanel>
  );
}
