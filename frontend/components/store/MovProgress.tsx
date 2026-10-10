import { Progress } from "@/components/ui/progress";
import type { Cart } from "@/lib/api/types";
import { ar } from "@/lib/copy/ar";
import { formatMoney } from "@/lib/format/money";
import { movProgress } from "@/lib/store/quantity";

/** "باقي 300.00 ج.م للحد الأدنى للطلب" with a progress bar (BR-MOV02); nothing when the store has no minimum. */
export function MovProgress({ cart }: { cart: Pick<Cart, "items_total" | "mov"> }) {
  const progress = movProgress(cart);

  if (progress === null) {
    return null;
  }

  return (
    <div className="flex flex-col gap-2 rounded-xl border p-3" data-testid="mov-progress">
      <p className={progress.satisfied ? "text-primary text-sm font-medium" : "text-sm font-medium"}>
        {progress.satisfied ? ar.cart.movReached : ar.cart.movRemaining(formatMoney(progress.remaining))}
      </p>
      <Progress value={progress.percent} aria-label={ar.cart.movLabel} />
    </div>
  );
}
