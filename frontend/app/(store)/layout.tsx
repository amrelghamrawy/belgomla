import { Cairo } from "next/font/google";
import { Suspense } from "react";

import { StoreBottomNav } from "@/components/store/StoreBottomNav";
import { StoreFooter } from "@/components/store/StoreFooter";
import { StoreHeader } from "@/components/store/StoreHeader";

// D67: the storefront's display face for headings and the store name; body text stays IBM Plex Sans Arabic (D40).
const cairo = Cairo({
  variable: "--font-cairo",
  subsets: ["arabic", "latin"],
  display: "swap",
});

export default function StoreLayout({ children }: LayoutProps<"/">) {
  return (
    <div className={`${cairo.variable} store-theme store-wallpaper bg-background text-foreground flex flex-1 flex-col`}>
      <StoreHeader />
      {children}
      <StoreFooter />
      {/* The bar reads the path (usePathname), which needs a Suspense boundary in Next 16. */}
      <Suspense>
        <StoreBottomNav />
      </Suspense>
    </div>
  );
}
