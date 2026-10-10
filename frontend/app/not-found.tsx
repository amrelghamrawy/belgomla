import Link from "next/link";

import { StatusPage } from "@/components/shared/StatusPage";
import { Button } from "@/components/ui/button";
import { ar } from "@/lib/copy/ar";

export default function NotFound() {
  return (
    <StatusPage
      title={ar.pages.notFoundTitle}
      body={ar.pages.notFoundBody}
      action={
        <Button asChild size="lg" className="h-11 px-6">
          <Link href="/">{ar.pages.backHome}</Link>
        </Button>
      }
    />
  );
}
