import { Suspense } from "react";

import { AccountStatement } from "@/components/store/AccountStatement";

export default function StatementPage() {
  return (
    // The date range lives in the URL (useSearchParams needs a Suspense boundary).
    <Suspense>
      <AccountStatement />
    </Suspense>
  );
}
