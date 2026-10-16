// BR-CU08: the merchant's customer-invitation text. Mirrors App\Support\InvitationMessage.

export const INVITATION_MAX_LENGTH = 500;

/** For the preview only: the backend's `customers.invitation.ttl_days`. */
export const INVITATION_TTL_DAYS = 7;

export const INVITATION_PLACEHOLDERS = {
  customer: "{اسم_العميل}",
  store: "{اسم_المتجر}",
  days: "{عدد_الأيام}",
} as const;

const KNOWN: readonly string[] = Object.values(INVITATION_PLACEHOLDERS);

/** Every `{...}` that is not a known placeholder, once each, in order of appearance. */
export function unknownPlaceholders(template: string): string[] {
  const found = template.match(/\{[^{}]*\}/g) ?? [];

  return [...new Set(found.filter((token) => !KNOWN.includes(token)))];
}

/** The text a customer receives, without the link the backend appends on its own last line. */
export function renderInvitation(template: string, values: { customer: string; store: string; days: number }): string {
  return template
    .replaceAll(INVITATION_PLACEHOLDERS.customer, values.customer)
    .replaceAll(INVITATION_PLACEHOLDERS.store, values.store)
    .replaceAll(INVITATION_PLACEHOLDERS.days, String(values.days))
    .trimEnd();
}

/** What to send: null keeps the platform default (and its future wording), so an untouched or empty box sends null. */
export function invitationBody(text: string, fallback: string): string | null {
  const trimmed = text.trim();

  return trimmed === "" || trimmed === fallback.trim() ? null : trimmed;
}

/** Puts `token` in place of the selection [start, end) and returns the new text and caret. */
export function insertAt(text: string, token: string, start: number, end: number): { text: string; caret: number } {
  return { text: text.slice(0, start) + token + text.slice(end), caret: start + token.length };
}
