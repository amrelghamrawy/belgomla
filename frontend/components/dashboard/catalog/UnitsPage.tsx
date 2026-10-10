"use client";

import { type FormEvent, useState } from "react";
import { toast } from "sonner";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { FieldError } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import type { Unit } from "@/lib/api/types";
import { ar, messageFor } from "@/lib/copy/ar";
import { useArchiveUnit, useSaveUnit, useUnits } from "@/lib/dashboard/catalog";

import { EmptyBlock, ErrorBlock, LoadingBlock, PageHeader } from "../common/States";

const copy = ar.dashboard.units;

/** Units: add, rename and archive. Archiving a unit any SKU uses is refused (409 in_use). */
export function UnitsPage() {
  const units = useUnits();

  return (
    <>
      <PageHeader title={copy.title} description={copy.description} />
      <UnitForm />
      {units.isError && <ErrorBlock error={units.error} onRetry={() => void units.refetch()} />}
      {units.isPending && <LoadingBlock rows={3} />}
      {units.data?.length === 0 && <EmptyBlock>{copy.empty}</EmptyBlock>}
      {units.data !== undefined && units.data.length > 0 && (
        <ul className="flex max-w-xl flex-col gap-2">
          {units.data.map((unit) => (
            <UnitForm key={`${unit.id}-${unit.name}`} unit={unit} />
          ))}
        </ul>
      )}
    </>
  );
}

/** The "add" row without a unit; an editable row with one. */
function UnitForm({ unit }: { unit?: Unit }) {
  const save = useSaveUnit();
  const archive = useArchiveUnit();
  const [name, setName] = useState(unit?.name ?? "");
  const [error, setError] = useState<string | null>(null);
  const archived = unit?.status === "archived";
  const id = unit === undefined ? "unit-new" : `unit-${unit.id}`;

  const submit = (event: FormEvent) => {
    event.preventDefault();

    if (name.trim() === "") {
      setError(ar.validation.required);
      return;
    }

    setError(null);
    save.mutate(
      { id: unit?.id, name: name.trim() },
      {
        onSuccess: () => {
          toast.success(ar.dashboard.saved);
          if (unit === undefined) {
            setName("");
          }
        },
        onError: (failure) => setError(messageFor(failure)),
      },
    );
  };

  const form = (
    <form onSubmit={submit} noValidate className="flex flex-col gap-1">
      <div className="flex items-center gap-2">
        <Input
          id={id}
          aria-label={copy.name}
          placeholder={unit === undefined ? copy.newPlaceholder : undefined}
          value={name}
          maxLength={50}
          disabled={archived}
          aria-invalid={error !== null}
          onChange={(event) => setName(event.target.value)}
          className="max-w-xs"
        />
        {archived ? (
          <Badge variant="secondary">{ar.dashboard.archived}</Badge>
        ) : (
          <Button type="submit" variant={unit === undefined ? "default" : "outline"} disabled={save.isPending}>
            {unit === undefined ? copy.add : ar.save}
          </Button>
        )}
        {unit !== undefined && !archived && (
          <Button
            type="button"
            variant="ghost"
            disabled={archive.isPending}
            onClick={() => archive.mutate(unit.id, { onError: (failure) => setError(messageFor(failure)) })}
          >
            {ar.dashboard.archive}
          </Button>
        )}
      </div>
      {error !== null && <FieldError role="alert">{error}</FieldError>}
    </form>
  );

  return unit === undefined ? <div className="max-w-xl">{form}</div> : <li>{form}</li>;
}
