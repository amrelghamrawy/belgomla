import { Badge } from "@/components/ui/badge";
import type { CustomerStatus, InviteResult } from "@/lib/api/types";
import { ar } from "@/lib/copy/ar";
import { formatDateTime } from "@/lib/format/date";

const VARIANTS: Record<CustomerStatus, "default" | "secondary" | "destructive" | "outline"> = {
  active: "default",
  invited: "outline",
  pending_approval: "secondary",
  pending_verification: "secondary",
  blocked: "destructive",
};

export function CustomerStatusBadge({ status }: { status: CustomerStatus }) {
  return <Badge variant={VARIANTS[status]}>{ar.dashboard.customerStatuses[status]}</Badge>;
}

/** "Sent via WhatsApp, valid until …": the channel the invitation actually went out on (BR-CU07). */
export function inviteMessage(sent: InviteResult): string {
  const copy = ar.dashboard.customers;

  return copy.invited(copy.channels[sent.sent_via] ?? sent.sent_via, formatDateTime(sent.expires_at));
}
