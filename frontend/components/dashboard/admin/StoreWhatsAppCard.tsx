"use client";

import Image from "next/image";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { useState } from "react";
import { toast } from "sonner";

import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { FieldError } from "@/components/ui/field";
import type { StoreWhatsApp } from "@/lib/api/types";
import { ar, messageFor } from "@/lib/copy/ar";
import {
  needsLink,
  needsRelink,
  useLinkWhatsApp,
  useStoreWhatsApp,
  useUnlinkWhatsApp,
} from "@/lib/dashboard/store-whatsapp";
import { formatDate } from "@/lib/format/date";
import { cn } from "@/lib/utils";

import { ErrorBlock, LoadingBlock } from "../common/States";

const copy = ar.dashboard.storeWhatsApp;
const SETTINGS = "/dashboard/settings";

function reasonText(reason: string | null): string | null {
  return reason === null || reason === "unlinked" ? null : (copy.reasons[reason] ?? copy.unknownReason);
}

/**
 * D70: link the store's own WhatsApp number by QR, see it, unlink it. Hidden while the platform has the feature
 * off. The status is re-read every few seconds while the QR is shown, so a scan shows up by itself.
 */
export function StoreWhatsAppCard() {
  const status = useStoreWhatsApp({ poll: true });

  if (status.isError) {
    return <ErrorBlock error={status.error} onRetry={() => void status.refetch()} />;
  }

  if (status.data === undefined) {
    return <LoadingBlock rows={3} />;
  }

  if (!status.data.enabled) {
    return null;
  }

  return <StoreWhatsAppPanel status={status.data} />;
}

export function StoreWhatsAppPanel({ status }: { status: StoreWhatsApp }) {
  const link = useLinkWhatsApp();
  const unlink = useUnlinkWhatsApp();
  const [error, setError] = useState<string | null>(null);
  const [confirming, setConfirming] = useState(false);
  const busy = link.isPending || unlink.isPending;

  const startLink = () => {
    setError(null);
    link.mutate(undefined, { onError: (failure) => setError(messageFor(failure)) });
  };

  const stopLink = () => {
    setError(null);
    setConfirming(false);
    unlink.mutate(undefined, {
      onSuccess: (answer) => {
        if (status.status === "connected" && answer.logged_out_on_phone === false) {
          toast.warning(copy.removeDevice);
        } else {
          toast.success(copy.unlinked);
        }
      },
      onError: (failure) => setError(messageFor(failure)),
    });
  };

  const reason = reasonText(status.disconnect_reason);

  return (
    <Card data-testid="store-whatsapp">
      <CardHeader>
        <CardTitle>{copy.title}</CardTitle>
        <CardDescription>{copy.intro}</CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        {error !== null && <FieldError role="alert">{error}</FieldError>}

        {status.status === "connected" && (
          <div className="flex flex-col gap-3">
            <dl className="grid grid-cols-1 gap-2 sm:grid-cols-2">
              <div>
                <dt className="text-muted-foreground text-sm">{copy.connectedAs}</dt>
                <dd className="font-medium">
                  <bdi>{status.phone}</bdi>
                </dd>
              </div>
              {status.connected_at !== null && (
                <div>
                  <dt className="text-muted-foreground text-sm">{copy.connectedSince}</dt>
                  <dd>{formatDate(status.connected_at)}</dd>
                </div>
              )}
            </dl>
            {reason !== null && <p className="text-muted-foreground text-sm">{reason}</p>}
            {confirming ? (
              <div className="flex flex-wrap items-center gap-2">
                <Button variant="destructive" onClick={stopLink} disabled={busy}>
                  {copy.confirmUnlink}
                </Button>
                <Button variant="outline" onClick={() => setConfirming(false)} disabled={busy}>
                  {copy.keep}
                </Button>
                <span className="text-muted-foreground text-sm">{copy.unlinkHint}</span>
              </div>
            ) : (
              <Button variant="outline" className="self-start" onClick={() => setConfirming(true)} disabled={busy}>
                {copy.unlink}
              </Button>
            )}
          </div>
        )}

        {status.status === "pairing" && (
          <div className="flex flex-col items-start gap-3">
            <p className="font-medium">{copy.scanTitle}</p>
            <p className="text-muted-foreground text-sm">{copy.scanSteps}</p>
            {status.qr === null ? (
              <div className="bg-muted text-muted-foreground grid size-64 place-items-center rounded-md border text-sm">
                {copy.preparingQr}
              </div>
            ) : (
              <Image
                src={status.qr}
                alt={copy.qrAlt}
                width={256}
                height={256}
                unoptimized
                className="size-64 rounded-md border bg-white p-2"
              />
            )}
            <Button variant="outline" onClick={stopLink} disabled={busy}>
              {copy.cancel}
            </Button>
          </div>
        )}

        {status.status !== "connected" && status.status !== "pairing" && (
          <div className="flex flex-col gap-3">
            {reason !== null && (
              <Alert variant={needsRelink(status) ? "destructive" : "default"}>
                <AlertDescription>{reason}</AlertDescription>
              </Alert>
            )}
            <ul className="text-muted-foreground list-disc space-y-1 ps-5 text-sm">
              {copy.notes.map((note) => (
                <li key={note}>{note}</li>
              ))}
            </ul>
            <Button className="self-start" onClick={startLink} disabled={busy}>
              {link.isPending ? copy.linking : needsRelink(status) ? copy.relink : copy.link}
            </Button>
          </div>
        )}
      </CardContent>
    </Card>
  );
}

/**
 * Shown at the top of every dashboard page while the store has no working WhatsApp number (D70): a warning
 * while it is dropped, a reminder while it was never linked. The whole bar opens the settings, where the card
 * is, so it is not shown there.
 */
export function StoreWhatsAppBanner() {
  const status = useStoreWhatsApp();
  const path = usePathname();

  if (!needsLink(status.data) || path.startsWith(SETTINGS)) {
    return null;
  }

  const dropped = needsRelink(status.data);

  return (
    <Link
      href={SETTINGS}
      data-testid="store-whatsapp-banner"
      className={cn(
        "flex flex-wrap items-center justify-between gap-x-3 gap-y-1 border-b px-4 py-2 text-sm hover:underline",
        dropped ? "bg-destructive/10 text-destructive" : "bg-muted text-foreground",
      )}
    >
      <span>{dropped ? copy.dropped : copy.notLinked}</span>
      <span className="font-medium">{dropped ? copy.droppedLink : copy.notLinkedLink}</span>
    </Link>
  );
}
