import { Suspense } from "react";

import { ExpensesPage } from "@/components/dashboard/expenses/ExpensesPage";

export default function Page() {
  return (
    // Filters and page live in the URL (useSearchParams needs a Suspense boundary).
    <Suspense>
      <ExpensesPage />
    </Suspense>
  );
}
