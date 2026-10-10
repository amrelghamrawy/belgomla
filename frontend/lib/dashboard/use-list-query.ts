"use client";

import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { useCallback, useMemo } from "react";

import { type ListQuery, listSearch, readListQuery } from "./list-query";

/**
 * A list's URL query and a setter that replaces the URL without scrolling. Reads useSearchParams(), so the
 * page must render the list inside <Suspense> (rules/03 §1).
 */
export function useListQuery(keys: readonly string[]) {
  const params = useSearchParams();
  const router = useRouter();
  const path = usePathname();
  const signature = keys.join(",");
  // eslint-disable-next-line react-hooks/exhaustive-deps -- `signature` stands in for the keys array
  const query = useMemo(() => readListQuery(new URLSearchParams(params.toString()), keys), [params, signature]);

  const update = useCallback(
    (patch: Record<string, string | number | null | undefined>) => {
      router.replace(`${path}${listSearch(query, patch)}`, { scroll: false });
    },
    [router, path, query],
  );

  return [query, update] as const satisfies readonly [ListQuery, unknown];
}
