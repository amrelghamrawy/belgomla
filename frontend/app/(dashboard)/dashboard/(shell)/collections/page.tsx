import { Suspense } from "react";

import { CollectionsPage } from "@/components/dashboard/collections/CollectionsPage";

export default function Page() {
  return (
    // Filters and page live in the URL (useSearchParams needs a Suspense boundary).
    <Suspense>
      <CollectionsPage />
    </Suspense>
  );
}
