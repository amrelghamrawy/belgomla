import type { ComponentProps } from "react";

import { cn } from "@/lib/utils";

/** A styled native select: the phone's own picker is the most usable one, and it works with `register()`. */
export function NativeSelect({ className, ...props }: ComponentProps<"select">) {
  return (
    <select
      className={cn(
        "border-input bg-background focus-visible:ring-ring/50 aria-invalid:border-destructive h-10 min-w-0 rounded-lg border px-3 text-sm outline-none focus-visible:ring-3",
        className,
      )}
      {...props}
    />
  );
}
