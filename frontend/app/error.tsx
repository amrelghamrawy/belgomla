"use client";

import { StatusPage } from "@/components/shared/StatusPage";
import { Button } from "@/components/ui/button";
import { ar, messageFor } from "@/lib/copy/ar";

export default function Error({ error, retry }: { error: Error & { digest?: string }; retry: () => void }) {
  return (
    <StatusPage
      title={ar.pages.errorTitle}
      body={messageFor(error)}
      action={
        <Button size="lg" className="h-11 px-6" onClick={retry}>
          {ar.retry}
        </Button>
      }
    />
  );
}
