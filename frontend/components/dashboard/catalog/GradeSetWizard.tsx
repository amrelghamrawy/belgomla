"use client";

import { LibraryIcon } from "lucide-react";
import { useRouter } from "next/navigation";
import { type FormEvent, useState } from "react";
import { toast } from "sonner";

import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Field, FieldLabel } from "@/components/ui/field";
import { NativeSelect } from "@/components/ui/native-select";
import { isApiError } from "@/lib/api/errors";
import type { FieldDefinition } from "@/lib/api/types";
import { ar, messageFor } from "@/lib/copy/ar";
import { gradeSetFilter, gradeSetSelection, useGenerateBundle } from "@/lib/dashboard/bundles";
import type { flatten } from "@/lib/dashboard/category-tree";

const copy = ar.dashboard.bundles.wizard;

type Props = {
  /** The products list URL: the wizard starts from its category and facets. */
  query: Record<string, string>;
  categories: ReturnType<typeof flatten>;
  /** The filterable custom fields (grade, term, publisher, …), as on the products page filters. */
  facets: FieldDefinition[];
};

/**
 * «إنشاء باقة صف دراسي» (BR-P15, GenerateGradeSet): pick a category and facet values (grade + term + publisher), and
 * the server makes an inactive bundle of every active product they match, one unit each, at 0 % off; the merchant
 * lands on its editor to review and activate it.
 */
export function GradeSetWizard({ query, categories, facets }: Props) {
  const router = useRouter();
  const generate = useGenerateBundle();
  const [open, setOpen] = useState(false);
  const [category, setCategory] = useState("");
  const [values, setValues] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);
  const filter = gradeSetFilter(category, values);

  const start = () => {
    const selection = gradeSetSelection(query, facets);
    setCategory(selection.category);
    setValues(selection.facets);
    setError(null);
    setOpen(true);
  };

  const submit = (event: FormEvent) => {
    event.preventDefault();

    if (filter === null) {
      setError(copy.needsFilter);
      return;
    }

    setError(null);
    generate.mutate(filter, {
      onSuccess: (bundle) => {
        toast.success(copy.created);
        setOpen(false);
        router.push(`/dashboard/bundles/${bundle.id}`);
      },
      onError: (failure) =>
        setError(
          (isApiError(failure, "validation_failed") ? failure.fieldMessage("filter") : null) ?? messageFor(failure),
        ),
    });
  };

  return (
    <>
      <Button variant="outline" onClick={start}>
        <LibraryIcon />
        {copy.open}
      </Button>
      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-lg">
          <DialogHeader>
            <DialogTitle>{copy.title}</DialogTitle>
            <DialogDescription>{copy.body}</DialogDescription>
          </DialogHeader>
          <form onSubmit={submit} noValidate className="flex flex-col gap-3">
            <Field>
              <FieldLabel htmlFor="grade-set-category">{copy.category}</FieldLabel>
              <NativeSelect
                id="grade-set-category"
                value={category}
                onChange={(event) => setCategory(event.target.value)}
              >
                <option value="">{copy.anyCategory}</option>
                {categories.map(({ node, depth }) => (
                  <option key={node.id} value={node.id}>
                    {`${"— ".repeat(depth)}${node.name}`}
                  </option>
                ))}
              </NativeSelect>
            </Field>
            {facets.map((facet) => (
              <Field key={facet.key}>
                <FieldLabel htmlFor={`grade-set-${facet.key}`}>{facet.label}</FieldLabel>
                <NativeSelect
                  id={`grade-set-${facet.key}`}
                  value={values[facet.key] ?? ""}
                  onChange={(event) => setValues((current) => ({ ...current, [facet.key]: event.target.value }))}
                >
                  <option value="">{copy.anyValue}</option>
                  {facet.type === "boolean" ? (
                    <>
                      <option value="true">{ar.dashboard.yes}</option>
                      <option value="false">{ar.dashboard.no}</option>
                    </>
                  ) : (
                    facet.options
                      .filter((option) => option.status === "active")
                      .map((option) => (
                        <option key={option.id} value={option.id}>
                          {option.label}
                        </option>
                      ))
                  )}
                </NativeSelect>
              </Field>
            ))}
            {error !== null && (
              <Alert variant="destructive" role="alert">
                <AlertDescription>{error}</AlertDescription>
              </Alert>
            )}
            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                {ar.cancel}
              </Button>
              <Button type="submit" disabled={generate.isPending}>
                {generate.isPending ? copy.creating : copy.create}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </>
  );
}
