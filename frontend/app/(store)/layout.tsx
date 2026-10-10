import { StoreFooter } from "@/components/store/StoreFooter";
import { StoreHeader } from "@/components/store/StoreHeader";

export default function StoreLayout({ children }: LayoutProps<"/">) {
  return (
    <>
      <StoreHeader />
      {children}
      <StoreFooter />
    </>
  );
}
