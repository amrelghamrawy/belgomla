"use client";

import { PlusIcon } from "lucide-react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState, type FormEvent } from "react";

import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { FieldError, FieldLabel } from "@/components/ui/field";
import { Label } from "@/components/ui/label";
import { RadioGroup, RadioGroupItem } from "@/components/ui/radio-group";
import { Skeleton } from "@/components/ui/skeleton";
import { Textarea } from "@/components/ui/textarea";
import { isApiError } from "@/lib/api/errors";
import { useRequiredCustomer } from "@/lib/auth/session";
import { ar, messageFor } from "@/lib/copy/ar";
import { formatMoney } from "@/lib/format/money";
import { useAddresses } from "@/lib/store/addresses";
import { useCart } from "@/lib/store/cart";
import { useCheckout } from "@/lib/store/checkout";
import { checkoutFailure, type CheckoutFailure } from "@/lib/store/checkout-errors";

import { AddressFormDialog } from "./AddressFormDialog";
import { AddressText } from "./AddressList";
import { MovProgress } from "./MovProgress";

const NOTE_MAX = 1000;

/**
 * `/checkout` (BR-PR06): choose the delivery address, add a note, place the order. The server re-prices and
 * re-checks everything; a refusal lists each line's problem with a link back to the product or the cart.
 */
export function CheckoutForm() {
  const router = useRouter();
  const session = useRequiredCustomer();
  const active = session.data?.status === "active";
  const cart = useCart(active);
  const addresses = useAddresses(active);
  const checkout = useCheckout();
  const [chosen, setChosen] = useState<number | null>(null);
  const [note, setNote] = useState("");
  const [adding, setAdding] = useState(false);
  const [failure, setFailure] = useState<CheckoutFailure | null>(null);
  const [error, setError] = useState<string | null>(null);

  if (!session.data) {
    return null;
  }

  const fallback = addresses.data?.find((address) => address.is_default) ?? addresses.data?.[0];
  const addressId = chosen ?? fallback?.id ?? null;

  const submit = (event: FormEvent) => {
    event.preventDefault();
    setFailure(null);

    if (addressId === null) {
      setError(ar.checkout.noAddress);
      return;
    }

    setError(null);
    checkout.mutate(
      { address_id: addressId, customer_note: note.trim() === "" ? null : note.trim() },
      {
        onSuccess: (order) => router.replace(`/checkout/success?order=${order.number}`),
        onError: (failed) => {
          const lines = checkoutFailure(failed, cart.data?.items ?? []);

          if (lines !== null) {
            setFailure(lines);
          } else if (isApiError(failed, "cart_empty")) {
            router.replace("/cart");
          } else {
            setError(messageFor(failed));
          }
        },
      },
    );
  };

  if (cart.data !== undefined && cart.data.items.length === 0 && !checkout.isPending && !checkout.isSuccess) {
    return (
      <main className="mx-auto flex w-full max-w-2xl flex-1 flex-col items-center gap-3 px-4 py-12 text-center">
        <p className="font-bold">{ar.cart.empty}</p>
        <Button asChild className="h-11">
          <Link href="/">{ar.cart.browse}</Link>
        </Button>
      </main>
    );
  }

  return (
    <main className="mx-auto flex w-full max-w-2xl flex-1 flex-col gap-5 px-4 pt-6 pb-32 md:pb-6">
      <h1 className="text-xl font-bold">{ar.checkout.title}</h1>

      {failure !== null && (
        <Alert variant="destructive" role="alert">
          <AlertTitle>{ar.checkout.problemsTitle}</AlertTitle>
          <AlertDescription>
            <ul className="flex flex-col gap-2">
              {failure.problems.map((problem) => (
                <li key={problem.skuId} className="flex flex-col">
                  <span className="font-medium">{problem.productName ?? `#${problem.skuId}`}</span>
                  <span>{problem.message}</span>
                  <span className="flex gap-3">
                    {problem.productSlug !== null && (
                      <Link href={`/p/${encodeURIComponent(problem.productSlug)}`} className="underline">
                        {ar.checkout.viewProduct}
                      </Link>
                    )}
                    <Link href="/cart" className="underline">
                      {ar.checkout.editInCart}
                    </Link>
                  </span>
                </li>
              ))}
              {failure.mov !== null && <li>{failure.mov}</li>}
            </ul>
          </AlertDescription>
        </Alert>
      )}

      <form id="checkout-form" onSubmit={submit} noValidate className="flex flex-col gap-5">
        <fieldset className="flex flex-col gap-3">
          <legend className="mb-2 font-bold">{ar.checkout.address}</legend>
          {addresses.data === undefined ? (
            <Skeleton className="h-20" />
          ) : addresses.data.length === 0 ? (
            <p className="text-muted-foreground text-sm">{ar.checkout.noAddress}</p>
          ) : (
            <RadioGroup
              value={addressId === null ? "" : String(addressId)}
              onValueChange={(value) => setChosen(Number(value))}
            >
              {addresses.data.map((address) => (
                <div
                  key={address.id}
                  className="has-data-checked:border-primary flex items-start gap-3 rounded-xl border p-3"
                >
                  <RadioGroupItem id={`address-${address.id}`} value={String(address.id)} className="mt-1" />
                  <Label htmlFor={`address-${address.id}`} className="flex-1 cursor-pointer font-normal">
                    <AddressText address={address} />
                  </Label>
                </div>
              ))}
            </RadioGroup>
          )}
          <Button type="button" variant="outline" className="h-11 w-fit" onClick={() => setAdding(true)}>
            <PlusIcon />
            {ar.checkout.addAddress}
          </Button>
        </fieldset>

        <div className="flex flex-col gap-2">
          <FieldLabel htmlFor="customer-note">{ar.checkout.note}</FieldLabel>
          <Textarea
            id="customer-note"
            value={note}
            maxLength={NOTE_MAX}
            onChange={(event) => setNote(event.target.value)}
            rows={3}
          />
        </div>

        {cart.data && <MovProgress cart={cart.data} />}
        {error !== null && <FieldError role="alert">{error}</FieldError>}
      </form>

      <div className="bg-background/95 fixed inset-x-0 bottom-0 z-30 border-t p-3 backdrop-blur md:static md:rounded-xl md:border">
        <div className="mx-auto flex max-w-2xl items-center gap-3">
          <div className="flex flex-1 flex-col">
            <span className="text-muted-foreground text-xs">{ar.checkout.total}</span>
            <span className="text-lg font-bold">{cart.data ? formatMoney(cart.data.items_total) : "—"}</span>
          </div>
          <Button
            type="submit"
            form="checkout-form"
            size="lg"
            className="h-11 flex-1"
            disabled={checkout.isPending || cart.data === undefined}
          >
            {checkout.isPending ? ar.checkout.placing : ar.checkout.placeOrder}
          </Button>
        </div>
      </div>

      <AddressFormDialog
        open={adding}
        onOpenChange={setAdding}
        address={null}
        makeDefault={addresses.data?.length === 0}
        onSaved={(address) => setChosen(address.id)}
      />
    </main>
  );
}
