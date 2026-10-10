import { Suspense } from "react";

import { CategoriesPage } from "@/components/dashboard/catalog/CategoriesPage";

export default function Page() {
  return (
    // The archived toggle lives in the URL (useSearchParams needs a Suspense boundary).
    <Suspense>
      <CategoriesPage />
    </Suspense>
  );
}
