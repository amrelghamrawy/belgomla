import type { CategoryKind, DashboardProduct, FieldDefinition } from "@/lib/api/types";

// The product form keeps custom field values as strings (what inputs hold) and converts at the edges.
// API values: select → option id, number → number, boolean → true/false, text → string (CustomFieldValidator).
// A definition bound to a section applies only to products of a category of that kind (D69); until a
// category is chosen (kind null) only the section-less definitions apply.

export type CustomFieldDrafts = Record<string, string>;

/** Whether a definition belongs to the form of a product of this section kind (D69). */
export function appliesTo(definition: FieldDefinition, kind: CategoryKind | null): boolean {
  return definition.kind === null || definition.kind === kind;
}

/**
 * The definitions the form shows: those of the product's section, active ones plus archived ones the product
 * still holds (read-only). Fields of another section stay hidden; the backend keeps their stored values.
 */
export function visibleDefinitions(
  definitions: readonly FieldDefinition[],
  drafts: CustomFieldDrafts,
  kind: CategoryKind | null,
): FieldDefinition[] {
  return definitions
    .filter((definition) => appliesTo(definition, kind))
    .filter((definition) => definition.status === "active" || (drafts[definition.key] ?? "") !== "")
    .sort((a, b) => a.sort_order - b.sort_order);
}

export function draftsFromProduct(values: DashboardProduct["custom_fields"]): CustomFieldDrafts {
  return Object.fromEntries(Object.entries(values).map(([key, value]) => [key, String(value)]));
}

/** The `custom_fields` body: fields of other sections and empty drafts are left out (an empty one clears the value). */
export function customFieldsBody(
  definitions: readonly FieldDefinition[],
  drafts: CustomFieldDrafts,
  kind: CategoryKind | null,
): Record<string, string | number | boolean> {
  const body: Record<string, string | number | boolean> = {};

  for (const definition of definitions) {
    const draft = (drafts[definition.key] ?? "").trim();

    if (draft === "" || !appliesTo(definition, kind)) {
      continue;
    }

    body[definition.key] =
      definition.type === "select" || definition.type === "number"
        ? Number(draft)
        : definition.type === "boolean"
          ? draft === "true"
          : draft;
  }

  return body;
}

/** Client-side problems before saving: required fields of the product's section and numbers that are not numbers. */
export function customFieldProblems(
  definitions: readonly FieldDefinition[],
  drafts: CustomFieldDrafts,
  kind: CategoryKind | null,
  messages: { required: string; number: string },
): Record<string, string> {
  const problems: Record<string, string> = {};

  for (const definition of definitions) {
    const draft = (drafts[definition.key] ?? "").trim();

    if (definition.status !== "active" || !appliesTo(definition, kind)) {
      continue;
    }

    if (draft === "" && definition.is_required) {
      problems[definition.key] = messages.required;
    } else if (draft !== "" && definition.type === "number" && !Number.isFinite(Number(draft))) {
      problems[definition.key] = messages.number;
    }
  }

  return problems;
}
