import { FolderIcon } from "lucide-react";
import Image from "next/image";
import Link from "next/link";

import type { Category } from "@/lib/api/types";

/** Category tiles linking to `/c/{slug}`. */
export function CategoryGrid({ categories }: { categories: readonly Category[] }) {
  return (
    <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
      {categories.map((category) => (
        <li key={category.id}>
          <Link
            href={`/c/${encodeURIComponent(category.slug)}`}
            className="bg-card hover:border-primary flex h-full min-h-16 items-center gap-3 rounded-xl border p-3 transition-colors"
          >
            {category.image_url ? (
              <Image
                src={category.image_url}
                alt=""
                width={40}
                height={40}
                className="size-10 rounded-md object-cover"
              />
            ) : (
              <FolderIcon className="text-primary size-6 shrink-0" aria-hidden />
            )}
            <span className="font-medium">{category.name}</span>
          </Link>
        </li>
      ))}
    </ul>
  );
}
