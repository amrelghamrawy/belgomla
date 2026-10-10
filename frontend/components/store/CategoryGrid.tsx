import Image from "next/image";
import Link from "next/link";

import type { Category } from "@/lib/api/types";
import { COVER_INKS } from "@/lib/store/cover";

/** A row of book spines in the cover inks, varied by category, for a tile without an image. */
function Spines({ seed }: { seed: number }) {
  return (
    <span aria-hidden className="flex h-14 items-end gap-[3px]">
      {Array.from({ length: 5 }, (_, index) => (
        <span
          key={index}
          className="block w-2.5 rounded-t-[2px]"
          style={{
            backgroundColor: COVER_INKS[(seed + index * 3) % COVER_INKS.length],
            height: `${36 + ((seed + index * 7) % 5) * 5}px`,
          }}
        />
      ))}
    </span>
  );
}

/**
 * Category tiles linking to `/c/{slug}` (D67): the name in the display face, the image or a row of spines, and the
 * subcategories as chips. The whole tile is the category link; the chips sit above it as their own links.
 */
export function CategoryGrid({ categories }: { categories: readonly Category[] }) {
  return (
    <ul className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
      {categories.map((category) => (
        <li
          key={category.id}
          className="bg-card hover:border-primary relative flex flex-col gap-3 rounded-2xl border p-4 transition-[border-color,translate] hover:-translate-y-0.5"
        >
          <div className="flex items-start justify-between gap-3">
            <Link
              href={`/c/${encodeURIComponent(category.slug)}`}
              className="font-display text-xl leading-snug font-semibold after:absolute after:inset-0 after:rounded-2xl"
            >
              {category.name}
            </Link>
            {category.image_url ? (
              <Image
                src={category.image_url}
                alt=""
                width={56}
                height={56}
                className="size-14 rounded-lg object-cover"
              />
            ) : (
              <Spines seed={category.id} />
            )}
          </div>
          {category.children.length > 0 && (
            <ul className="relative z-10 flex flex-wrap gap-1.5">
              {category.children.map((child) => (
                <li key={child.id}>
                  <Link
                    href={`/c/${encodeURIComponent(child.slug)}`}
                    className="bg-brand-soft text-primary hover:bg-primary hover:text-primary-foreground inline-block rounded-full px-2.5 py-1 text-xs font-medium"
                  >
                    {child.name}
                  </Link>
                </li>
              ))}
            </ul>
          )}
        </li>
      ))}
    </ul>
  );
}
