import Image from "next/image";

import { monogram } from "@/lib/store/cover";
import { cn } from "@/lib/utils";

/** The store's logo, or its initial on the primary colour with a highlight foot when it has none (D67). */
export function StoreMark({ name, logoUrl, className }: { name: string; logoUrl: string | null; className?: string }) {
  if (logoUrl !== null) {
    return (
      <Image
        src={logoUrl}
        alt=""
        width={40}
        height={40}
        className={cn("size-10 rounded-lg object-contain", className)}
      />
    );
  }

  return (
    <span
      aria-hidden
      className={cn(
        "bg-primary text-primary-foreground font-display after:bg-highlight relative grid size-10 shrink-0 place-items-center overflow-hidden rounded-lg text-xl font-bold after:absolute after:inset-x-0 after:bottom-0 after:h-1.5",
        className,
      )}
    >
      {monogram(name)}
    </span>
  );
}
