// Timestamps arrive as ISO-8601 UTC and are shown in Africa/Cairo (Tech §9), Arabic month names, Western digits.
const LOCALE = "ar-EG-u-nu-latn";
const TIME_ZONE = "Africa/Cairo";

const dateFormat = new Intl.DateTimeFormat(LOCALE, {
  timeZone: TIME_ZONE,
  day: "numeric",
  month: "long",
  year: "numeric",
});
const dateTimeFormat = new Intl.DateTimeFormat(LOCALE, {
  timeZone: TIME_ZONE,
  day: "numeric",
  month: "long",
  year: "numeric",
  hour: "numeric",
  minute: "2-digit",
});

function parse(iso: string): Date {
  const date = new Date(iso);

  if (Number.isNaN(date.getTime())) {
    throw new RangeError(`Not an ISO date: "${iso}"`);
  }

  return date;
}

/** "2026-07-01T21:30:00Z" → "2 يوليو 2026" (Cairo is UTC+3 in summer). */
export function formatDate(iso: string): string {
  return dateFormat.format(parse(iso));
}

/** "2026-01-15T10:05:00Z" → "15 يناير 2026 في 12:05 م". */
export function formatDateTime(iso: string): string {
  return dateTimeFormat.format(parse(iso));
}
