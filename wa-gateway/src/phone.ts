/** `+201001234567` → `201001234567`; null when it is not an E.164 number. */
export function e164Digits(phone: string): string | null {
  return /^\+[1-9]\d{7,14}$/.test(phone) ? phone.slice(1) : null;
}

/** The linked account's own id (`201001234567:12@s.whatsapp.net`) → `+201001234567`. */
export function phoneFromJid(jid: string | undefined): string | null {
  const user = jid?.split('@')[0]?.split(':')[0] ?? '';

  return /^[1-9]\d{7,14}$/.test(user) ? `+${user}` : null;
}
