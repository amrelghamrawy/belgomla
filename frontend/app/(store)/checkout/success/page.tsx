import { Suspense } from "react";

import { CheckoutSuccess } from "@/components/store/CheckoutSuccess";

export default function CheckoutSuccessPage() {
  return (
    // Reads ?order= (useSearchParams needs Suspense under cacheComponents).
    <Suspense>
      <CheckoutSuccess />
    </Suspense>
  );
}
