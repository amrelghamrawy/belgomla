import { Suspense } from "react";

import { PriceListView } from "@/components/store/PriceListView";

export default function PriceListPage() {
  return (
    <Suspense>
      <PriceListView />
    </Suspense>
  );
}
