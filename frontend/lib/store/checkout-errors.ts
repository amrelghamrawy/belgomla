import { isApiError } from "@/lib/api/errors";
import type { CartItem, LineProblem } from "@/lib/api/types";
import { ar } from "@/lib/copy/ar";
import { formatMoney } from "@/lib/format/money";

export type CheckoutProblem = {
  skuId: number;
  productName: string | null;
  productSlug: string | null;
  message: string;
};

export type CheckoutFailure = { problems: CheckoutProblem[]; mov: string | null };

function isLineProblem(item: Record<string, unknown>): item is LineProblem & Record<string, unknown> {
  return typeof item.sku_id === "number" && typeof item.reason === "string";
}

function reasonText(problem: LineProblem, unit: string): string {
  switch (problem.reason) {
    case "insufficient_stock":
      return ar.checkout.reasons.insufficient_stock(problem.available ?? 0, unit);
    case "below_moq":
      return ar.checkout.reasons.below_moq(problem.moq ?? 1, unit);
    case "product_inactive":
      return ar.checkout.reasons.product_inactive;
  }
}

/**
 * Turns `422 checkout_failed` / `cart_line_invalid` into one line per SKU (BR-PR06: the error names the SKU),
 * matched to the cart line for its name and a link back to the product. Null for any other error.
 */
export function checkoutFailure(error: unknown, lines: readonly CartItem[]): CheckoutFailure | null {
  if (!isApiError(error, "checkout_failed") && !isApiError(error, "cart_line_invalid")) {
    return null;
  }

  const problems = (error.items ?? []).filter(isLineProblem).map((problem) => {
    const line = lines.find((item) => item.sku_id === problem.sku_id);

    return {
      skuId: problem.sku_id,
      productName: line?.product_name ?? null,
      productSlug: line?.product_slug ?? null,
      message: reasonText(problem, line?.unit ?? ""),
    };
  });

  const mov = error.details?.mov;
  const movText =
    typeof mov === "object" && mov !== null && "required" in mov && "current" in mov
      ? ar.checkout.movShort(formatMoney(String(mov.required)), formatMoney(String(mov.current)))
      : null;

  return { problems, mov: movText };
}
