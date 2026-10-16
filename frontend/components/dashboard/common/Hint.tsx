"use client";

import type { ReactElement } from "react";

import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";

/**
 * A supplementary hint shown on hover or keyboard focus. Taps do not open it, so it must never carry
 * something the user needs: put that on the page. A non-focusable trigger (a badge) needs `tabIndex={0}`.
 */
export function Hint({ text, children }: { text: string; children: ReactElement }) {
  return (
    <Tooltip>
      <TooltipTrigger asChild>{children}</TooltipTrigger>
      <TooltipContent>{text}</TooltipContent>
    </Tooltip>
  );
}
