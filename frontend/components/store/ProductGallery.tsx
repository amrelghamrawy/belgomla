"use client";

import Image from "next/image";
import { useState, type ReactNode } from "react";

import type { ProductImage } from "@/lib/api/types";
import { ar } from "@/lib/copy/ar";
import { cn } from "@/lib/utils";

/** The main image with thumbnails underneath; the main image comes first. Without images, `fallback` (a generated cover). */
export function ProductGallery({
  images,
  name,
  fallback,
}: {
  images: readonly ProductImage[];
  name: string;
  fallback: ReactNode;
}) {
  const ordered = [...images].sort((a, b) => Number(b.is_main) - Number(a.is_main) || a.sort_order - b.sort_order);
  const [current, setCurrent] = useState(0);
  const shown = ordered[current] ?? ordered[0];

  return (
    <div className="flex flex-col gap-2">
      <div className="bg-muted relative mx-auto aspect-[3/4] w-full max-w-60 overflow-hidden rounded-xl sm:max-w-sm md:mx-0 md:max-w-md">
        {shown ? (
          <Image
            src={shown.url}
            alt={name}
            fill
            priority
            sizes="(min-width: 768px) 448px, 100vw"
            className="object-contain"
          />
        ) : (
          <span className="bg-brand-soft block size-full p-4" role="img" aria-label={ar.product.noImage}>
            {fallback}
          </span>
        )}
      </div>
      {ordered.length > 1 && (
        <ul className="flex gap-2 overflow-x-auto">
          {ordered.map((image, index) => (
            <li key={image.id}>
              <button
                type="button"
                onClick={() => setCurrent(index)}
                aria-label={ar.product.gallery(index + 1, ordered.length)}
                aria-current={index === current}
                className={cn(
                  "relative block size-16 overflow-hidden rounded-md border-2",
                  index === current ? "border-primary" : "border-transparent",
                )}
              >
                <Image src={image.url} alt="" fill sizes="64px" className="object-cover" />
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
