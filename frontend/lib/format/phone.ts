/** "+201012345678" (E.164, how the API returns phones) → "01012345678". Anything else is returned as is. */
export function formatPhone(phone: string): string {
  return /^\+20\d{10}$/.test(phone) ? `0${phone.slice(3)}` : phone;
}

/** A link that opens a WhatsApp chat with the number: "+201012345678" → "https://wa.me/201012345678". */
export function whatsappLink(phone: string): string {
  return `https://wa.me/${phone.replace(/\D/g, "")}`;
}
