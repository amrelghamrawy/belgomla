"use client";

import { useState } from "react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { NativeSelect } from "@/components/ui/native-select";
import type { DashboardProduct, ProductRelation } from "@/lib/api/types";
import { ar, messageFor } from "@/lib/copy/ar";
import { useAddProductRelation, useProductSearch, useRemoveProductRelation } from "@/lib/dashboard/catalog";

const copy = ar.dashboard.productForm;

/** Related products in both directions, and a search to add one (BR-P10). */
export function ProductRelations({ product }: { product: DashboardProduct }) {
  const [q, setQ] = useState("");
  const [type, setType] = useState<ProductRelation["type"]>("companion");
  const search = useProductSearch(q);
  const add = useAddProductRelation(product.id);
  const remove = useRemoveProductRelation(product.id);
  const relations = product.relations ?? [];
  const related = new Set(relations.map((relation) => relation.product.id));
  const candidates = (search.data?.data ?? []).filter(
    (candidate) => candidate.id !== product.id && !related.has(candidate.id),
  );

  return (
    <div className="flex flex-col gap-4">
      {relations.length === 0 ? (
        <p className="text-muted-foreground text-sm">{copy.noRelations}</p>
      ) : (
        <ul className="flex flex-col gap-2">
          {relations.map((relation) => (
            <li key={relation.id} className="flex items-center justify-between gap-2 rounded-md border p-2">
              <span className="min-w-0 truncate">
                <span className="text-muted-foreground text-xs">
                  {relation.direction === "incoming" ? copy.incoming : copy.relationTypes[relation.type]}{" "}
                </span>
                {relation.product.name}
              </span>
              {relation.direction === "outgoing" && (
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  disabled={remove.isPending}
                  onClick={() => remove.mutate(relation.id, { onError: (error) => toast.error(messageFor(error)) })}
                >
                  {copy.removeRelation}
                </Button>
              )}
            </li>
          ))}
        </ul>
      )}
      <div className="flex flex-col gap-2 rounded-md border p-3">
        <div className="flex flex-wrap gap-2">
          <Input
            aria-label={copy.relationSearch}
            placeholder={copy.relationSearch}
            value={q}
            onChange={(event) => setQ(event.target.value)}
            className="min-w-48 flex-1"
          />
          <NativeSelect
            aria-label={copy.relationType}
            value={type}
            onChange={(event) => setType(event.target.value as ProductRelation["type"])}
          >
            <option value="companion">{copy.relationTypes.companion}</option>
            <option value="revision_of">{copy.relationTypes.revision_of}</option>
          </NativeSelect>
        </div>
        {candidates.length > 0 && (
          <ul className="flex flex-col gap-1">
            {candidates.map((candidate) => (
              <li key={candidate.id} className="flex items-center justify-between gap-2">
                <span className="min-w-0 truncate text-sm">{candidate.name}</span>
                <Button
                  type="button"
                  size="sm"
                  variant="outline"
                  disabled={add.isPending}
                  onClick={() =>
                    add.mutate(
                      { related_product_id: candidate.id, type },
                      { onSuccess: () => setQ(""), onError: (error) => toast.error(messageFor(error)) },
                    )
                  }
                >
                  {copy.addRelation}
                </Button>
              </li>
            ))}
          </ul>
        )}
      </div>
    </div>
  );
}
