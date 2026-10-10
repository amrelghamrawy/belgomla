import type { DashboardProduct, FieldDefinition } from "@/lib/api/types";

// The product form keeps custom field values as strings (what inputs hold) and converts at the edges.
// API values: select → option id, number → number, boolean → true/false, text → string (CustomFieldValidator).

export type CustomFieldDrafts = Record<string, string>;

/** The definitions the form shows: active ones, plus archived ones the product still holds (read-only). */
export function visibleDefinitions(
  definitions: readonly FieldDefinition[],
  drafts: CustomFieldDrafts,
): FieldDefinition[] {
  return definitions
    .filter((definition) => definition.status === "active" || (drafts[definition.key] ?? "") !== "")
    .sort((a, b) => a.sort_order - b.sort_order);
}

export function draftsFromProduct(values: DashboardProduct["custom_fields"]): CustomFieldDrafts {
  return Object.fromEntries(Object.entries(values).map(([key, value]) => [key, String(value)]));
}

/** The `custom_fields` body: empty drafts are left out, which clears that value on update. */
export function customFieldsBody(
  definitions: readonly FieldDefinition[],
  drafts: CustomFieldDrafts,
): Record<string, string | number | boolean> {
  const body: Record<string, string | number | boolean> = {};

  for (const definition of definitions) {
    const draft = (drafts[definition.key] ?? "").trim();

    if (draft === "") {
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

/** Client-side problems before saving: required fields and numbers that are not numbers. */
export function customFieldProblems(
  definitions: readonly FieldDefinition[],
  drafts: CustomFieldDrafts,
  messages: { required: string; number: string },
): Record<string, string> {
  const problems: Record<string, string> = {};

  for (const definition of definitions) {
    const draft = (drafts[definition.key] ?? "").trim();

    if (definition.status !== "active") {
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
