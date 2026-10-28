"use client";

import { PlusIcon, TrashIcon, XIcon } from "lucide-react";
import { type KeyboardEvent, useState } from "react";
import { type UseFormReturn, useWatch } from "react-hook-form";

import { Button } from "@/components/ui/button";
import { Field, FieldError, FieldLabel } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { NativeSelect } from "@/components/ui/native-select";
import type { ProductImage } from "@/lib/api/types";
import { ar } from "@/lib/copy/ar";
import { type OptionFormValues, type ProductFormValues, syncVariants } from "@/lib/dashboard/product-form";
import { MAX_OPTION_TEXT, MAX_OPTION_VALUES, MAX_OPTIONS } from "@/lib/dashboard/variants";

import { LabelText } from "../common/LabelText";

const copy = ar.dashboard.productForm.options;

type Props = {
  form: UseFormReturn<ProductFormValues>;
  /** The product's images, offered as a value's swatch; none before the first save. */
  images: readonly ProductImage[];
  /** Called after the combinations changed (the variant rows were regenerated). */
  onCombinationsChange: () => void;
};

/** An error on an array field sits on the array itself or, from the resolver, on its `root`. */
function arrayMessage(error: { message?: string; root?: { message?: string } } | undefined): string | undefined {
  return error?.message ?? error?.root?.message;
}

/**
 * «خيارات المنتج» (V2 Task 14, BR-P14, D92): up to two options, each a name and its values as chips, with an optional
 * swatch image per value. Adding or removing a value or an option regenerates the variant rows (one per combination),
 * keeping the rows of combinations that stay.
 */
export function ProductOptionsEditor({ form, images, onCombinationsChange }: Props) {
  const options = useWatch({ control: form.control, name: "options" });
  const errors = form.formState.errors.options;

  const apply = (next: OptionFormValues[]) => {
    const values = form.getValues();
    const validate = form.formState.isSubmitted;

    form.setValue("options", next, { shouldDirty: true });
    form.setValue(
      "variants",
      next.length === 0
        ? []
        : syncVariants(values.variants, next, values.code, { units: values.units, default_unit: values.default_unit }),
      { shouldDirty: true },
    );
    onCombinationsChange();

    if (validate) {
      void form.trigger(["options", "variants"]);
    }
  };

  const update = (index: number, option: OptionFormValues) =>
    apply(options.map((current, at) => (at === index ? option : current)));

  return (
    <section className="flex flex-col gap-4 rounded-xl border p-4" aria-labelledby="product-options-title">
      <div>
        <h3 id="product-options-title" className="font-bold">
          {copy.title}
        </h3>
        <p className="text-muted-foreground text-xs">{copy.hint}</p>
      </div>
      {arrayMessage(errors) !== undefined && <FieldError>{arrayMessage(errors)}</FieldError>}
      {options.map((option, index) => (
        <OptionCard
          key={index}
          index={index}
          option={option}
          images={images}
          nameError={errors?.[index]?.name?.message}
          valuesError={arrayMessage(errors?.[index]?.values)}
          onRename={(name) =>
            form.setValue(`options.${index}.name`, name, {
              shouldDirty: true,
              shouldValidate: form.formState.isSubmitted,
            })
          }
          onImage={(valueIndex, imageId) =>
            form.setValue(`options.${index}.values.${valueIndex}.image_id`, imageId, { shouldDirty: true })
          }
          onChange={(next) => update(index, next)}
          onRemove={() => apply(options.filter((_, at) => at !== index))}
        />
      ))}
      {options.length < MAX_OPTIONS && (
        <Button
          type="button"
          variant="outline"
          className="self-start"
          onClick={() => apply([...options, { id: "", name: "", values: [] }])}
        >
          <PlusIcon />
          {copy.add}
        </Button>
      )}
    </section>
  );
}

type CardProps = {
  index: number;
  option: OptionFormValues;
  images: readonly ProductImage[];
  nameError: string | undefined;
  valuesError: string | undefined;
  onRename: (name: string) => void;
  onImage: (valueIndex: number, imageId: string) => void;
  onChange: (option: OptionFormValues) => void;
  onRemove: () => void;
};

function OptionCard({
  index,
  option,
  images,
  nameError,
  valuesError,
  onRename,
  onImage,
  onChange,
  onRemove,
}: CardProps) {
  const [draft, setDraft] = useState("");
  const [problem, setProblem] = useState<string | null>(null);
  const id = (name: string) => `product-options-${index}-${name}`;
  const label = option.name.trim() === "" ? copy.name(index + 1) : option.name.trim();

  const addValue = () => {
    const value = draft.trim();

    if (value === "") {
      return;
    }

    if (value.length > MAX_OPTION_TEXT) {
      setProblem(copy.tooLong(MAX_OPTION_TEXT));
    } else if (option.values.some((existing) => existing.value.trim().toLowerCase() === value.toLowerCase())) {
      setProblem(copy.valueDuplicate);
    } else if (option.values.length >= MAX_OPTION_VALUES) {
      setProblem(copy.valuesAtMost(MAX_OPTION_VALUES));
    } else {
      setProblem(null);
      setDraft("");
      onChange({ ...option, values: [...option.values, { id: "", value, image_id: "" }] });
    }
  };

  const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
    if (event.key === "Enter") {
      event.preventDefault();
      addValue();
    }
  };

  return (
    <fieldset className="flex flex-col gap-3 rounded-lg border p-3" data-testid={`product-option-${index}`}>
      <legend className="sr-only">{copy.name(index + 1)}</legend>
      <div className="flex items-end gap-2">
        <Field className="min-w-0 flex-1" data-invalid={nameError !== undefined}>
          <FieldLabel htmlFor={id("name")}>
            <LabelText text={copy.name(index + 1)} need="required" />
          </FieldLabel>
          <Input
            id={id("name")}
            value={option.name}
            maxLength={MAX_OPTION_TEXT}
            placeholder={copy.namePlaceholder}
            aria-invalid={nameError !== undefined}
            onChange={(event) => onRename(event.target.value)}
          />
        </Field>
        <Button type="button" variant="ghost" size="icon" aria-label={copy.remove} onClick={onRemove}>
          <TrashIcon />
        </Button>
      </div>
      <FieldError errors={[{ message: nameError }]} />
      <ul className="flex flex-wrap gap-2" aria-label={`${copy.values}: ${label}`}>
        {option.values.map((value, valueIndex) => (
          <li
            key={`${value.id}-${value.value}`}
            className="bg-muted flex max-w-full items-center gap-1 rounded-full py-1 ps-3 pe-1 text-sm"
          >
            <span className="truncate">{value.value}</span>
            {images.length > 0 && (
              <NativeSelect
                aria-label={copy.valueImage(value.value)}
                className="h-7 text-xs"
                value={value.image_id}
                onChange={(event) => onImage(valueIndex, event.target.value)}
              >
                <option value="">{copy.noImage}</option>
                {images.map((image, imageIndex) => (
                  <option key={image.id} value={image.id}>
                    {copy.imageN(imageIndex + 1)}
                  </option>
                ))}
              </NativeSelect>
            )}
            <Button
              type="button"
              variant="ghost"
              size="icon"
              className="size-7 rounded-full"
              aria-label={copy.removeValue(value.value)}
              onClick={() => onChange({ ...option, values: option.values.filter((_, at) => at !== valueIndex) })}
            >
              <XIcon />
            </Button>
          </li>
        ))}
      </ul>
      <div className="flex gap-2">
        <Input
          aria-label={copy.newValue(label)}
          value={draft}
          maxLength={MAX_OPTION_TEXT}
          placeholder={copy.valuePlaceholder}
          className="min-w-0 flex-1"
          onChange={(event) => {
            setDraft(event.target.value);
            setProblem(null);
          }}
          onKeyDown={onKeyDown}
        />
        <Button type="button" variant="outline" onClick={addValue}>
          <PlusIcon />
          {copy.addValue}
        </Button>
      </div>
      <FieldError errors={[{ message: problem ?? valuesError }]} />
    </fieldset>
  );
}
