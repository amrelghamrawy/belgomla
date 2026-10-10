import { ChevronRightIcon } from "lucide-react";
import Link from "next/link";

import type { Category } from "@/lib/api/types";
import { ar } from "@/lib/copy/ar";

/** Home, then every parent of a category from the root down, then the category itself (not a link). */
export function CategoryBreadcrumbs({ path }: { path: readonly Category[] }) {
  const current = path.at(-1);
  const parents = path.slice(0, -1);

  if (current === undefined) {
    return null;
  }

  return (
    <nav aria-label={ar.catalog.breadcrumbs}>
      <ol className="text-muted-foreground flex flex-wrap items-center gap-1 text-sm">
        <Crumb href="/" name={ar.store.home} />
        {parents.map((parent) => (
          <Crumb key={parent.id} href={`/c/${encodeURIComponent(parent.slug)}`} name={parent.name} />
        ))}
        <li aria-current="page" className="text-foreground min-w-0 truncate font-medium">
          {current.name}
        </li>
      </ol>
    </nav>
  );
}

function Crumb({ href, name }: { href: string; name: string }) {
  return (
    <li className="flex min-w-0 items-center gap-1">
      <Link href={href} className="hover:text-primary truncate underline-offset-4 hover:underline">
        {name}
      </Link>
      <ChevronRightIcon className="size-3.5 shrink-0 rtl:rotate-180" aria-hidden />
    </li>
  );
}
