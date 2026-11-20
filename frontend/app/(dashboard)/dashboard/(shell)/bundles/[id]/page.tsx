import { Suspense } from "react";

import { BundleEditPage } from "@/components/dashboard/catalog/BundleEditPage";

export default function Page() {
  return (
    // The bundle id is request-time data under cacheComponents (useParams suspends).
    <Suspense>
      <BundleEditPage />
    </Suspense>
  );
}
