"use client";

import { useRef } from "react";

import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { Field, FieldError, FieldLabel } from "@/components/ui/field";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { ar } from "@/lib/copy/ar";
import { insertAt } from "@/lib/dashboard/invitation-message";
import {
  NOTIFIABLE_STATUSES,
  type NotifiableStatus,
  ORDER_MESSAGE_MAX_LENGTH,
  ORDER_MESSAGE_PLACEHOLDERS,
  placeholdersFor,
  renderOrderMessage,
} from "@/lib/dashboard/order-status-message";

const copy = ar.dashboard.settings.orderMessages;

type OrderMessagesCardProps = {
  storeName: string;
  statuses: NotifiableStatus[];
  texts: Record<NotifiableStatus, string>;
  defaults: Record<NotifiableStatus, string>;
  errors: Partial<Record<NotifiableStatus, string>>;
  onStatuses: (statuses: NotifiableStatus[]) => void;
  onText: (status: NotifiableStatus, text: string) => void;
};

/** BR-N03: which order moves message the customer, and the merchant's words for each, with a live preview. */
export function OrderMessagesCard({
  storeName,
  statuses,
  texts,
  defaults,
  errors,
  onStatuses,
  onText,
}: OrderMessagesCardProps) {
  const toggle = (status: NotifiableStatus, on: boolean) =>
    onStatuses(NOTIFIABLE_STATUSES.filter((each) => (each === status ? on : statuses.includes(each))));

  return (
    <Card data-testid="order-messages">
      <CardHeader>
        <CardTitle>{copy.title}</CardTitle>
        <p className="text-muted-foreground text-xs">{copy.hint}</p>
      </CardHeader>
      <CardContent className="flex flex-col gap-4 divide-y">
        {NOTIFIABLE_STATUSES.map((status) => (
          <StatusMessage
            key={status}
            status={status}
            storeName={storeName}
            enabled={statuses.includes(status)}
            text={texts[status]}
            fallback={defaults[status]}
            error={errors[status]}
            onToggle={(on) => toggle(status, on)}
            onText={(text) => onText(status, text)}
          />
        ))}
      </CardContent>
    </Card>
  );
}

type StatusMessageProps = {
  status: NotifiableStatus;
  storeName: string;
  enabled: boolean;
  text: string;
  fallback: string;
  error: string | undefined;
  onToggle: (on: boolean) => void;
  onText: (text: string) => void;
};

function StatusMessage({ status, storeName, enabled, text, fallback, error, onToggle, onText }: StatusMessageProps) {
  const box = useRef<HTMLTextAreaElement | null>(null);
  const label =
    status === "discount" ? copy.discountEvent : status === "edited" ? copy.editedEvent : ar.orders.statuses[status];
  const id = `order-message-${status}`;

  const insert = (token: string) => {
    const area = box.current;
    const next = insertAt(text, token, area?.selectionStart ?? text.length, area?.selectionEnd ?? text.length);
    onText(next.text);
    requestAnimationFrame(() => {
      area?.focus();
      area?.setSelectionRange(next.caret, next.caret);
    });
  };

  return (
    <div className="flex flex-col gap-3 pt-4 first:pt-0" data-testid={id}>
      <div className="flex items-center gap-2">
        <Checkbox id={`${id}-enabled`} checked={enabled} onCheckedChange={(checked) => onToggle(checked === true)} />
        <Label htmlFor={`${id}-enabled`}>{copy.notify(label)}</Label>
      </div>
      {enabled && (
        <>
          <Field data-invalid={error !== undefined}>
            <FieldLabel htmlFor={id}>{copy.label(label)}</FieldLabel>
            <div className="flex flex-wrap items-center gap-2">
              <span className="text-muted-foreground text-xs">{copy.insert}</span>
              {placeholdersFor(status).map((key) => (
                <Button
                  key={key}
                  type="button"
                  variant="outline"
                  size="sm"
                  onClick={() => insert(ORDER_MESSAGE_PLACEHOLDERS[key])}
                >
                  {copy.placeholders[key]}
                </Button>
              ))}
            </div>
            <Textarea
              id={id}
              ref={box}
              rows={3}
              maxLength={ORDER_MESSAGE_MAX_LENGTH}
              aria-invalid={error !== undefined}
              value={text}
              onChange={(event) => onText(event.target.value)}
            />
            <div className="flex items-center justify-between gap-2">
              <Button type="button" variant="link" size="sm" className="px-0" onClick={() => onText(fallback)}>
                {copy.restore}
              </Button>
              <span className="text-muted-foreground text-xs tabular-nums" dir="ltr">
                {ar.dashboard.settings.invitation.count(text.length, ORDER_MESSAGE_MAX_LENGTH)}
              </span>
            </div>
            <FieldError>{error}</FieldError>
          </Field>
          <div className="bg-muted rounded-md p-3" data-testid={`${id}-preview`}>
            <p className="text-muted-foreground mb-1 text-xs">{copy.preview}</p>
            <p className="text-sm whitespace-pre-line">
              {renderOrderMessage(text, {
                customer: copy.sample.customer,
                store: storeName,
                number: copy.sample.number,
                status: label,
                total: copy.sample.total,
                reason: copy.sample.reason,
              })}
            </p>
          </div>
        </>
      )}
    </div>
  );
}
