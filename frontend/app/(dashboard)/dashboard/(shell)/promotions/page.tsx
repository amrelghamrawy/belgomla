import { Suspense } from "react";

import { PromotionsPage } from "@/components/dashboard/pricing/PromotionsPage";

export default function Page() {
  return (
    // The status filter and page live in the URL (useSearchParams needs a Suspense boundary).
    <Suspense>
      <PromotionsPage />
    </Suspense>
  );
}
