"use client";

import { PencilIcon, PlusIcon, Trash2Icon } from "lucide-react";
import { useState } from "react";
import { toast } from "sonner";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Skeleton } from "@/components/ui/skeleton";
import type { Address } from "@/lib/api/types";
import { useRequiredCustomer } from "@/lib/auth/session";
import { ar, messageFor } from "@/lib/copy/ar";
import { formatPhone } from "@/lib/format/phone";
import { useAddresses, useDeleteAddress } from "@/lib/store/addresses";

import { AddressFormDialog } from "./AddressFormDialog";

/** One address as text: label, then the lines a courier needs. */
export function AddressText({
  address,
}: {
  address: Pick<Address, "label" | "governorate" | "city" | "address_line" | "landmark">;
}) {
  return (
    <span className="flex flex-col gap-0.5">
      <span className="font-medium">{address.label}</span>
      <span className="text-muted-foreground text-sm">
        {address.address_line}، {address.city}، {address.governorate}
        {address.landmark ? ` — ${address.landmark}` : ""}
      </span>
    </span>
  );
}

/** `/account/addresses` (BR-CU03): list, add, edit, delete (archive), choose the default. */
export function AddressList() {
  const session = useRequiredCustomer();
  const addresses = useAddresses(session.data?.status === "active");
  const remove = useDeleteAddress();
  const [editing, setEditing] = useState<Address | null>(null);
  const [formOpen, setFormOpen] = useState(false);
  const [deleting, setDeleting] = useState<Address | null>(null);

  if (!session.data) {
    return null;
  }

  const open = (address: Address | null) => {
    setEditing(address);
    setFormOpen(true);
  };

  return (
    <main className="mx-auto flex w-full max-w-2xl flex-1 flex-col gap-4 px-4 py-6">
      <div className="flex items-center justify-between gap-2">
        <h1 className="text-xl font-bold">{ar.addresses.title}</h1>
        <Button className="h-11" onClick={() => open(null)}>
          <PlusIcon />
          {ar.addresses.add}
        </Button>
      </div>

      {addresses.isError ? (
        <p role="alert" className="text-destructive">
          {messageFor(addresses.error)}
        </p>
      ) : addresses.data === undefined ? (
        <Skeleton className="h-24" />
      ) : addresses.data.length === 0 ? (
        <p className="text-muted-foreground py-8 text-center">{ar.addresses.empty}</p>
      ) : (
        <ul className="flex flex-col gap-3">
          {addresses.data.map((address) => (
            <li key={address.id} className="flex items-start gap-3 rounded-xl border p-3">
              <div className="flex flex-1 flex-col gap-1">
                <AddressText address={address} />
                <bdi className="text-muted-foreground text-sm">{formatPhone(address.phone)}</bdi>
                {address.is_default && <Badge className="w-fit">{ar.addresses.default}</Badge>}
              </div>
              <Button
                variant="ghost"
                size="icon"
                className="size-11"
                aria-label={ar.addresses.edit}
                onClick={() => open(address)}
              >
                <PencilIcon />
              </Button>
              <Button
                variant="ghost"
                size="icon"
                className="text-destructive size-11"
                aria-label={ar.delete}
                onClick={() => setDeleting(address)}
              >
                <Trash2Icon />
              </Button>
            </li>
          ))}
        </ul>
      )}

      <AddressFormDialog
        open={formOpen}
        onOpenChange={setFormOpen}
        address={editing}
        makeDefault={addresses.data?.length === 0}
      />

      <Dialog open={deleting !== null} onOpenChange={(next) => !next && setDeleting(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{ar.addresses.deleteConfirm}</DialogTitle>
            <DialogDescription>{ar.addresses.deleteBody}</DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" className="h-11" onClick={() => setDeleting(null)}>
              {ar.cancel}
            </Button>
            <Button
              variant="destructive"
              className="h-11"
              disabled={remove.isPending}
              onClick={() =>
                deleting &&
                remove.mutate(deleting.id, {
                  onSuccess: () => {
                    toast.success(ar.addresses.deleted);
                    setDeleting(null);
                  },
                  onError: (error) => toast.error(messageFor(error)),
                })
              }
            >
              {ar.delete}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </main>
  );
}
