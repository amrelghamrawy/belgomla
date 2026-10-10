import type { CSSProperties } from "react";

import type { StoreProduct } from "@/lib/api/types";
import { COVER_INKS, coverFor } from "@/lib/store/cover";
import { cn } from "@/lib/utils";

function Decoration({ layout }: { layout: number }) {
  if (layout === 0) {
    return (
      <span
        className="absolute -end-[18cqi] -top-[18cqi] size-[70cqi] rounded-full"
        style={{
          background:
            "radial-gradient(circle, rgb(255 230 120 / 0.9) 0 18%, transparent 19%), repeating-conic-gradient(rgb(255 255 255 / 0.16) 0 6deg, transparent 6deg 18deg)",
        }}
      />
    );
  }

  if (layout === 1) {
    return (
      <>
        <span className="absolute -start-[30cqi] top-[12cqi] size-[90cqi] rounded-full border-[9cqi] border-white/15" />
        <span className="absolute inset-x-0 bottom-0 h-[30%] bg-black/20" />
      </>
    );
  }

  return (
    <>
      <span
        className="absolute inset-0"
        style={{
          background: "repeating-linear-gradient(45deg, rgb(255 255 255 / 0.07) 0 6cqi, transparent 6cqi 12cqi)",
        }}
      />
      <span className="absolute inset-y-0 end-[14%] w-[4%] bg-[#f5c400]" />
    </>
  );
}

/**
 * The cover a product without a photo gets (D67): an outside-textbook cover in the subject's ink, with the
 * publisher label, the subject, the grade and the term. Decorative: the product name is always next to it.
 */
export function GeneratedCover({ product, className }: { product: StoreProduct; className?: string }) {
  const cover = coverFor(product);
  const ink = COVER_INKS[cover.tone] ?? COVER_INKS[0];
  const shade = cover.layout === 0 ? `color-mix(in oklch, ${ink} 65%, black)` : ink;
  const style: CSSProperties = { background: `linear-gradient(155deg, ${ink} 0 58%, ${shade} 58% 100%)` };

  return (
    <div
      aria-hidden
      data-testid="generated-cover"
      className={cn(
        "@container relative flex aspect-[3/4] w-full flex-col overflow-hidden rounded-t-sm p-[9%] text-white shadow-[inset_-6px_0_0_rgb(0_0_0/0.14)]",
        className,
      )}
      style={style}
    >
      <Decoration layout={cover.layout} />
      {cover.badge !== null && (
        <span
          className="relative self-start rounded-[2cqi] bg-white px-[4cqi] py-[1.5cqi] text-[7.5cqi] leading-normal font-bold"
          style={{ color: ink }}
        >
          {cover.badge}
        </span>
      )}
      <span
        className={cn(
          "font-display relative mt-auto line-clamp-3 leading-tight font-bold [text-shadow:0_2px_0_rgb(0_0_0/0.18)]",
          cover.compact ? "text-[11cqi]" : "text-[20cqi]",
        )}
      >
        {cover.title}
      </span>
      {cover.subtitle !== null && (
        <span className="relative mt-[2cqi] line-clamp-2 text-[8.5cqi] font-semibold">{cover.subtitle}</span>
      )}
      {cover.note !== null && <span className="relative mt-[1cqi] text-[6.5cqi] opacity-85">{cover.note}</span>}
    </div>
  );
}
