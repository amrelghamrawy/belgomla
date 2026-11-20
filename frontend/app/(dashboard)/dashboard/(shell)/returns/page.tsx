import { Suspense } from "react";

import { ReturnsPage } from "@/components/dashboard/returns/ReturnsPage";

export default function Page() {
  return (
    // Filters and page live in the URL (useSearchParams needs a Suspense boundary).
    <Suspense>
      <ReturnsPage />
    </Suspense>
  );
}
