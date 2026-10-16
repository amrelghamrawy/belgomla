"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import Image from "next/image";
import { type CSSProperties, useRef, useState } from "react";
import { Controller, useForm, useWatch } from "react-hook-form";
import { toast } from "sonner";
import { z } from "zod";

import { TextField } from "@/components/shared/TextField";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { Field, FieldError, FieldLabel } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Switch } from "@/components/ui/switch";
import { Textarea } from "@/components/ui/textarea";
import type { DashboardSettings } from "@/lib/api/types";
import { readableOn } from "@/lib/auth/branding";
import { ar, messageFor } from "@/lib/copy/ar";
import { hoursBody, hoursRows, useDashboardSettings, useSaveSettings, useUploadBranding } from "@/lib/dashboard/admin";
import { toPiasters } from "@/lib/dashboard/price-math";
import { applyServerErrors } from "@/lib/forms/server-errors";

import { ErrorBlock, LoadingBlock, PageHeader } from "../common/States";
import { StoreWhatsAppCard } from "./StoreWhatsAppCard";

const copy = ar.dashboard.settings;
const COLOR = /^#[0-9A-Fa-f]{6}$/;

// Mirrors UpdateSettingsRequest.
const schema = z
  .object({
    name: z.string().trim().min(1, ar.validation.required).max(150),
    primary_color: z.string().regex(COLOR, copy.invalidColor),
    secondary_color: z.string().regex(COLOR, copy.invalidColor),
    phone: z.string().trim().max(20),
    whatsapp: z.string().trim().max(20),
    address: z.string().trim().max(1000),
    description: z.string().trim().max(5000),
    hours: z.array(z.object({ day: z.number(), open: z.boolean(), from: z.string(), to: z.string() })),
    mov_enabled: z.boolean(),
    mov_amount: z.string().trim(),
    require_login_for_prices: z.boolean(),
    require_customer_approval: z.boolean(),
    stale_pending_hours: z
      .string()
      .regex(/^\d{1,3}$/, ar.dashboard.productForm.invalidInteger)
      .refine((value) => Number(value) >= 1 && Number(value) <= 720, ar.dashboard.productForm.invalidInteger),
  })
  .superRefine((values, context) => {
    if (values.mov_enabled && (toPiasters(values.mov_amount) === null || toPiasters(values.mov_amount) === 0n)) {
      context.addIssue({ code: "custom", path: ["mov_amount"], message: copy.movRequired });
    }
  });

type Values = z.infer<typeof schema>;

const SERVER_FIELDS = [
  "name",
  "primary_color",
  "secondary_color",
  "phone",
  "whatsapp",
  "address",
  "description",
  "mov_amount",
  "stale_pending_hours",
] as const;

function defaults(settings: DashboardSettings): Values {
  return {
    name: settings.name,
    primary_color: settings.colors.primary,
    secondary_color: settings.colors.secondary,
    phone: settings.phone ?? "",
    whatsapp: settings.whatsapp ?? "",
    address: settings.address ?? "",
    description: settings.description ?? "",
    hours: hoursRows(settings.working_hours),
    mov_enabled: settings.mov.enabled,
    mov_amount: settings.mov.amount ?? "",
    require_login_for_prices: settings.require_login_for_prices,
    require_customer_approval: settings.require_customer_approval,
    stale_pending_hours: String(settings.stale_pending_hours),
  };
}

export function SettingsPage() {
  const settings = useDashboardSettings();

  if (settings.isError) {
    return <ErrorBlock error={settings.error} onRetry={() => void settings.refetch()} />;
  }

  if (settings.data === undefined) {
    return <LoadingBlock rows={8} />;
  }

  return (
    // The bottom padding keeps the last card clear of the form's fixed save bar on phones.
    <div className="flex max-w-3xl flex-col gap-4 pb-20 md:pb-0">
      <SettingsForm settings={settings.data} />
      <StoreWhatsAppCard />
    </div>
  );
}

/** Branding, contact, working hours, MOV and the visibility/approval switches, saved in one PUT. */
export function SettingsForm({ settings }: { settings: DashboardSettings }) {
  const save = useSaveSettings();
  const [error, setError] = useState<string | null>(null);
  const form = useForm<Values>({ resolver: zodResolver(schema), defaultValues: defaults(settings) });
  const errors = form.formState.errors;
  const [primary, secondary, movEnabled, hours] = useWatch({
    control: form.control,
    name: ["primary_color", "secondary_color", "mov_enabled", "hours"],
  });

  const submit = form.handleSubmit((values) => {
    setError(null);
    const empty = (value: string) => (value === "" ? null : value);
    save.mutate(
      {
        name: values.name,
        primary_color: values.primary_color,
        secondary_color: values.secondary_color,
        phone: empty(values.phone),
        whatsapp: empty(values.whatsapp),
        address: empty(values.address),
        description: empty(values.description),
        working_hours: hoursBody(values.hours),
        mov_enabled: values.mov_enabled,
        mov_amount: values.mov_enabled ? values.mov_amount : empty(values.mov_amount),
        require_login_for_prices: values.require_login_for_prices,
        require_customer_approval: values.require_customer_approval,
        stale_pending_hours: Number(values.stale_pending_hours),
      },
      {
        onSuccess: () => toast.success(copy.saved),
        onError: (failure) => {
          if (!applyServerErrors(failure, SERVER_FIELDS, form.setError)) {
            setError(messageFor(failure));
          }
        },
      },
    );
  });

  const color = (name: "primary_color" | "secondary_color", label: string) => (
    <Field data-invalid={errors[name] !== undefined}>
      <FieldLabel htmlFor={`settings-${name}`}>{label}</FieldLabel>
      <div className="flex items-center gap-2">
        <Controller
          control={form.control}
          name={name}
          render={({ field }) => (
            <input
              type="color"
              aria-label={label}
              className="h-10 w-12 cursor-pointer rounded border"
              value={COLOR.test(field.value) ? field.value : "#000000"}
              onChange={(event) => field.onChange(event.target.value.toUpperCase())}
            />
          )}
        />
        <Input id={`settings-${name}`} dir="ltr" className="w-32 text-start" maxLength={7} {...form.register(name)} />
      </div>
      <FieldError errors={[errors[name]]} />
    </Field>
  );

  const toggle = (name: "mov_enabled" | "require_login_for_prices" | "require_customer_approval", label: string) => (
    <Controller
      control={form.control}
      name={name}
      render={({ field }) => (
        <div className="flex items-center gap-2">
          <Switch id={`settings-${name}`} checked={field.value} onCheckedChange={field.onChange} />
          <Label htmlFor={`settings-${name}`}>{label}</Label>
        </div>
      )}
    />
  );

  // The preview sets the theme tokens on its own box, so it shows the colors before they are saved.
  const preview = {
    "--primary": COLOR.test(primary) ? primary : settings.colors.primary,
    "--primary-foreground": readableOn(COLOR.test(primary) ? primary : settings.colors.primary),
    "--secondary": COLOR.test(secondary) ? secondary : settings.colors.secondary,
    "--secondary-foreground": readableOn(COLOR.test(secondary) ? secondary : settings.colors.secondary),
  } as CSSProperties;

  return (
    <form onSubmit={submit} noValidate className="flex max-w-3xl flex-col gap-4">
      <PageHeader
        title={copy.title}
        actions={
          <Button type="submit" className="hidden md:inline-flex" disabled={save.isPending}>
            {save.isPending ? ar.saving : ar.save}
          </Button>
        }
      />
      {error !== null && <FieldError role="alert">{error}</FieldError>}
      <Card>
        <CardHeader>
          <CardTitle>{copy.branding}</CardTitle>
        </CardHeader>
        <CardContent className="grid gap-4 md:grid-cols-2">
          <div className="md:col-span-2">
            <TextField
              id="settings-name"
              label={copy.storeName}
              registration={form.register("name")}
              error={errors.name}
            />
          </div>
          {color("primary_color", copy.primaryColor)}
          {color("secondary_color", copy.secondaryColor)}
          <div
            style={preview}
            className="bg-secondary text-secondary-foreground rounded-md border p-4 md:col-span-2"
            data-testid="brand-preview"
          >
            <p className="text-muted-foreground mb-2 text-xs">{copy.preview}</p>
            <p className="text-primary mb-2 font-bold">{settings.name}</p>
            <p className="mb-3 text-sm">{copy.previewText}</p>
            <span className="bg-primary text-primary-foreground inline-flex h-9 items-center rounded-md px-4 text-sm">
              {copy.previewButton}
            </span>
          </div>
          <BrandingImage kind="logo" label={copy.logo} url={settings.logo_url} />
          <BrandingImage kind="favicon" label={copy.favicon} url={settings.favicon_url} />
        </CardContent>
      </Card>
      <Card>
        <CardHeader>
          <CardTitle>{copy.contact}</CardTitle>
        </CardHeader>
        <CardContent className="grid gap-4 md:grid-cols-2">
          <TextField
            id="settings-phone"
            label={copy.phone}
            dir="ltr"
            inputMode="tel"
            registration={form.register("phone")}
            error={errors.phone}
          />
          <TextField
            id="settings-whatsapp"
            label={copy.whatsapp}
            dir="ltr"
            inputMode="tel"
            registration={form.register("whatsapp")}
            error={errors.whatsapp}
          />
          <Field className="md:col-span-2">
            <FieldLabel htmlFor="settings-address">{copy.address}</FieldLabel>
            <Textarea id="settings-address" rows={2} {...form.register("address")} />
          </Field>
          <Field className="md:col-span-2">
            <FieldLabel htmlFor="settings-description">{copy.description}</FieldLabel>
            <Textarea id="settings-description" rows={3} {...form.register("description")} />
          </Field>
        </CardContent>
      </Card>
      <Card>
        <CardHeader>
          <CardTitle>{copy.hours}</CardTitle>
          <p className="text-muted-foreground text-xs">{copy.hoursHint}</p>
        </CardHeader>
        <CardContent>
          <ul className="flex flex-col gap-2">
            {hours.map((row, index) => (
              <li key={row.day} className="flex flex-wrap items-center gap-3" data-testid={`hours-${row.day}`}>
                <Controller
                  control={form.control}
                  name={`hours.${index}.open`}
                  render={({ field }) => (
                    <div className="flex w-28 items-center gap-2">
                      <Checkbox
                        id={`hours-open-${row.day}`}
                        checked={field.value}
                        onCheckedChange={(checked) => field.onChange(checked === true)}
                      />
                      <Label htmlFor={`hours-open-${row.day}`}>{copy.days[row.day]}</Label>
                    </div>
                  )}
                />
                <Input
                  type="time"
                  aria-label={`${copy.days[row.day]} ${copy.from}`}
                  className="w-32"
                  disabled={!row.open}
                  {...form.register(`hours.${index}.from`)}
                />
                <Input
                  type="time"
                  aria-label={`${copy.days[row.day]} ${copy.to}`}
                  className="w-32"
                  disabled={!row.open}
                  {...form.register(`hours.${index}.to`)}
                />
              </li>
            ))}
          </ul>
        </CardContent>
      </Card>
      <Card>
        <CardHeader>
          <CardTitle>{copy.ordering}</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col gap-4">
          {toggle("mov_enabled", copy.movEnabled)}
          {movEnabled && (
            <TextField
              id="settings-mov-amount"
              label={copy.movAmount}
              dir="ltr"
              inputMode="decimal"
              className="max-w-40"
              registration={form.register("mov_amount")}
              error={errors.mov_amount}
            />
          )}
          {toggle("require_login_for_prices", copy.requireLogin)}
          {toggle("require_customer_approval", copy.requireApproval)}
          <TextField
            id="settings-stale"
            label={copy.staleHours}
            dir="ltr"
            inputMode="numeric"
            className="max-w-24"
            registration={form.register("stale_pending_hours")}
            error={errors.stale_pending_hours}
          />
        </CardContent>
      </Card>
      <div className="bg-background/95 fixed inset-x-0 bottom-0 z-30 border-t p-3 backdrop-blur md:hidden">
        <Button type="submit" className="h-11 w-full" disabled={save.isPending}>
          {save.isPending ? ar.saving : ar.save}
        </Button>
      </div>
    </form>
  );
}

function BrandingImage({ kind, label, url }: { kind: "logo" | "favicon"; label: string; url: string | null }) {
  const input = useRef<HTMLInputElement>(null);
  const upload = useUploadBranding();

  return (
    <div className="flex items-center gap-3">
      <div className="bg-muted grid size-16 shrink-0 place-items-center overflow-hidden rounded-md border">
        {url === null ? (
          <span className="text-muted-foreground text-center text-xs">{copy.noImage}</span>
        ) : (
          <Image src={url} alt={label} width={64} height={64} className="size-16 object-contain" />
        )}
      </div>
      <div className="flex flex-col gap-1">
        <span className="text-sm font-medium">{label}</span>
        <input
          ref={input}
          type="file"
          className="sr-only"
          aria-label={`${copy.upload} ${label}`}
          accept={
            kind === "favicon"
              ? "image/png,image/x-icon,image/svg+xml"
              : "image/png,image/jpeg,image/webp,image/svg+xml"
          }
          onChange={(event) => {
            const file = event.target.files?.[0];

            if (file !== undefined) {
              upload.mutate(
                { kind, file },
                {
                  onSuccess: () => toast.success(copy.uploaded),
                  onError: (failure) => toast.error(messageFor(failure)),
                },
              );
            }

            event.target.value = "";
          }}
        />
        <Button
          type="button"
          variant="outline"
          size="sm"
          disabled={upload.isPending}
          onClick={() => input.current?.click()}
        >
          {upload.isPending ? ar.saving : copy.upload}
        </Button>
      </div>
    </div>
  );
}
