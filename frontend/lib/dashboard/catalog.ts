"use client";

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { apiFetch, apiFetchPage, withQuery } from "@/lib/api/client";
import { queryKeys } from "@/lib/api/keys";
import type {
  BulkStatusResult,
  DashboardCategory,
  DashboardProduct,
  FieldDefinition,
  FieldOption,
  FieldRequest,
  FieldType,
  ProductFilter,
  ProductImage,
  ProductRelation,
  ProductRequest,
  ProductStatus,
  Unit,
} from "@/lib/api/types";

import type { ListQuery } from "./list-query";

// Catalog management (08 §6): categories, units, custom fields, products, images and relations.

// ---- Categories ----

/** The whole tree, archived nodes included (the screen filters them on the client). */
export function useDashboardCategories() {
  return useQuery({
    queryKey: queryKeys.dashboardCategories,
    queryFn: () => apiFetch<DashboardCategory[]>("/api/dashboard/categories"),
  });
}

export type CategoryInput = { name: string; parent_id: number | null };

/** Creates a category, or renames/moves one (PUT with parent_id: null makes it a root). */
export function useSaveCategory() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, ...body }: CategoryInput & { id?: number }) =>
      id === undefined
        ? apiFetch<DashboardCategory>("/api/dashboard/categories", { method: "POST", body })
        : apiFetch<DashboardCategory>(`/api/dashboard/categories/${id}`, { method: "PUT", body }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: queryKeys.dashboardCategories }),
  });
}

export function useCategoryStatus() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, action }: { id: number; action: "archive" | "restore" }) =>
      apiFetch<DashboardCategory>(`/api/dashboard/categories/${id}/${action}`, { method: "POST" }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: queryKeys.dashboardCategories }),
  });
}

/** Saves the new order of one sibling list; the response is the whole tree. */
export function useReorderCategories() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (order: { id: number; sort_order: number }[]) =>
      apiFetch<DashboardCategory[]>("/api/dashboard/categories/reorder", { method: "POST", body: order }),
    onSuccess: (tree) => queryClient.setQueryData(queryKeys.dashboardCategories, tree),
    onError: () => queryClient.invalidateQueries({ queryKey: queryKeys.dashboardCategories }),
  });
}

// ---- Units ----

export function useUnits() {
  return useQuery({
    queryKey: queryKeys.dashboardUnits,
    queryFn: () => apiFetch<Unit[]>("/api/dashboard/units"),
  });
}

export function useSaveUnit() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, name }: { id?: number; name: string }) =>
      id === undefined
        ? apiFetch<Unit>("/api/dashboard/units", { method: "POST", body: { name } })
        : apiFetch<Unit>(`/api/dashboard/units/${id}`, { method: "PUT", body: { name } }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: queryKeys.dashboardUnits }),
  });
}

export function useArchiveUnit() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: number) => apiFetch<Unit>(`/api/dashboard/units/${id}/archive`, { method: "POST" }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: queryKeys.dashboardUnits }),
  });
}

// ---- Custom fields ----

export function useFields() {
  return useQuery({
    queryKey: queryKeys.dashboardFields,
    queryFn: () => apiFetch<FieldDefinition[]>("/api/dashboard/fields"),
  });
}

export type FieldInput = FieldRequest & { key?: string; options?: string[] };

/** Creates a field (key and initial options allowed) or updates one (key fixed, options edited one by one). */
export function useSaveField() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, ...body }: FieldInput & { id?: number }) =>
      id === undefined
        ? apiFetch<FieldDefinition>("/api/dashboard/fields", { method: "POST", body })
        : apiFetch<FieldDefinition>(`/api/dashboard/fields/${id}`, {
            method: "PUT",
            body: { ...body, key: undefined, options: undefined },
          }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: queryKeys.dashboardFields }),
  });
}

export function useArchiveField() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: number) => apiFetch<FieldDefinition>(`/api/dashboard/fields/${id}/archive`, { method: "POST" }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: queryKeys.dashboardFields }),
  });
}

export function useSaveFieldOption(fieldId: number) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, ...body }: { id?: number; label: string; sort_order?: number }) =>
      id === undefined
        ? apiFetch<FieldOption>(`/api/dashboard/fields/${fieldId}/options`, { method: "POST", body })
        : apiFetch<FieldOption>(`/api/dashboard/fields/${fieldId}/options/${id}`, { method: "PUT", body }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: queryKeys.dashboardFields }),
  });
}

export function useArchiveFieldOption(fieldId: number) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: number) =>
      apiFetch<FieldOption>(`/api/dashboard/fields/${fieldId}/options/${id}/archive`, { method: "POST" }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: queryKeys.dashboardFields }),
  });
}

/** The field types; select fields carry options. */
export const FIELD_TYPES: readonly FieldType[] = ["text", "number", "select", "boolean"];

// ---- Products ----

/** The list keys a products URL understands (facets `f[key]` are always read). */
export const PRODUCT_LIST_KEYS = ["q", "category", "status", "low_stock", "sort", "page"] as const;

export function useDashboardProducts(query: ListQuery) {
  return useQuery({
    queryKey: queryKeys.dashboardProductsList(query),
    queryFn: () => apiFetchPage<DashboardProduct>(withQuery("/api/dashboard/products", query)),
    placeholderData: keepPreviousData,
  });
}

/**
 * The bulk filter equal to the list's current filters (BR-P12): bulk actions apply to every product the
 * list matches, not to a hand-picked selection, because the API takes a filter. Blocked when nothing is
 * filtered (an empty filter is refused, so a mistake cannot hit the whole catalog) and while the low-stock
 * filter is on, which the bulk filter does not have: the action would reach products the list hides.
 */
export type BulkScope = { filter: ProductFilter } | { blocked: "empty" | "low_stock" };

export function bulkFilterOf(query: ListQuery): BulkScope {
  if (query.low_stock !== undefined) {
    return { blocked: "low_stock" };
  }

  const filter: ProductFilter = {};
  const facets: Record<string, string> = {};

  if (query.q !== undefined) {
    filter.q = query.q;
  }

  if (query.category !== undefined) {
    filter.category = Number(query.category);
  }

  if (query.status !== undefined) {
    filter.status = query.status as ProductStatus;
  }

  for (const [key, value] of Object.entries(query)) {
    const facet = /^f\[(.+)\]$/.exec(key);

    if (facet?.[1] !== undefined) {
      facets[facet[1]] = value;
    }
  }

  if (Object.keys(facets).length > 0) {
    filter.f = facets;
  }

  return Object.keys(filter).length > 0 ? { filter } : { blocked: "empty" };
}

export function useDashboardProduct(id: number | undefined) {
  return useQuery({
    queryKey: queryKeys.dashboardProduct(id ?? 0),
    queryFn: () => apiFetch<DashboardProduct>(`/api/dashboard/products/${id}`),
    enabled: id !== undefined,
  });
}

export type ProductCreateInput = ProductRequest & { status?: ProductStatus; opening_stock?: number };

/** Creates the product with its SKU, or replaces it; the response is the full product. */
export function useSaveProduct() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, body }: { id?: number; body: ProductCreateInput }) =>
      id === undefined
        ? apiFetch<DashboardProduct>("/api/dashboard/products", { method: "POST", body })
        : apiFetch<DashboardProduct>(`/api/dashboard/products/${id}`, { method: "PUT", body }),
    onSuccess: (product) => {
      queryClient.setQueryData(queryKeys.dashboardProduct(product.id), product);
      void queryClient.invalidateQueries({ queryKey: queryKeys.dashboardProductsLists });
    },
  });
}

export function useSetProductStatus(id: number) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (status: ProductStatus) =>
      apiFetch<DashboardProduct>(`/api/dashboard/products/${id}/status`, { method: "PATCH", body: { status } }),
    onSuccess: (product) => {
      queryClient.setQueryData(queryKeys.dashboardProduct(product.id), product);
      void queryClient.invalidateQueries({ queryKey: queryKeys.dashboardProductsLists });
    },
  });
}

/** BR-P11/D30: an inactive copy with SKU {code}-COPY, no stock, images or relations. */
export function useDuplicateProduct() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: number) =>
      apiFetch<DashboardProduct>(`/api/dashboard/products/${id}/duplicate`, { method: "POST" }),
    onSuccess: (copy) => {
      queryClient.setQueryData(queryKeys.dashboardProduct(copy.id), copy);
      void queryClient.invalidateQueries({ queryKey: queryKeys.dashboardProductsLists });
    },
  });
}

export function useBulkProductStatus() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (body: { filter: ProductFilter; status: ProductStatus }) =>
      apiFetch<BulkStatusResult>("/api/dashboard/products/bulk/status", { method: "POST", body }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["dashboard", "products"] }),
  });
}

// ---- Images and relations (edit only) ----

function useProductPart<TInput, TResult>(productId: number, request: (input: TInput) => Promise<TResult>) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: request,
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: queryKeys.dashboardProduct(productId) });
      void queryClient.invalidateQueries({ queryKey: queryKeys.dashboardProductsLists });
    },
  });
}

export function useUploadProductImage(productId: number) {
  return useProductPart(productId, ({ file, isMain }: { file: File; isMain: boolean }) => {
    const form = new FormData();
    form.append("file", file);
    form.append("is_main", isMain ? "1" : "0");

    return apiFetch<ProductImage>(`/api/dashboard/products/${productId}/images`, { method: "POST", body: form });
  });
}

export function useDeleteProductImage(productId: number) {
  return useProductPart(productId, (imageId: number) =>
    apiFetch<void>(`/api/dashboard/products/${productId}/images/${imageId}`, { method: "DELETE" }),
  );
}

export function useReorderProductImages(productId: number) {
  return useProductPart(productId, (ids: number[]) =>
    apiFetch<ProductImage[]>(`/api/dashboard/products/${productId}/images/reorder`, { method: "POST", body: { ids } }),
  );
}

export function useAddProductRelation(productId: number) {
  return useProductPart(productId, (body: { related_product_id: number; type: ProductRelation["type"] }) =>
    apiFetch<ProductRelation>(`/api/dashboard/products/${productId}/relations`, { method: "POST", body }),
  );
}

export function useRemoveProductRelation(productId: number) {
  return useProductPart(productId, (relationId: number) =>
    apiFetch<void>(`/api/dashboard/products/${productId}/relations/${relationId}`, { method: "DELETE" }),
  );
}

/** Products matching a search, for the relations picker. */
export function useProductSearch(q: string) {
  return useQuery({
    queryKey: queryKeys.dashboardProductsList({ q, picker: true }),
    queryFn: () => apiFetchPage<DashboardProduct>(withQuery("/api/dashboard/products", { q, per_page: 10 })),
    enabled: q.trim().length >= 2,
  });
}
