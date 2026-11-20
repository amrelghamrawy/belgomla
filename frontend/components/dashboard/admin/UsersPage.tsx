"use client";

import { KeyRoundIcon, UserCheckIcon, UserXIcon } from "lucide-react";
import { zodResolver } from "@hookform/resolvers/zod";
import { useState } from "react";
import { useForm, useWatch } from "react-hook-form";
import { toast } from "sonner";
import { z } from "zod";

import { TextField } from "@/components/shared/TextField";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { FieldError, FieldLabel } from "@/components/ui/field";
import { NativeSelect } from "@/components/ui/native-select";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import type { TenantUser } from "@/lib/api/types";
import { useDashboardSession } from "@/lib/auth/session";
import { ar, messageFor } from "@/lib/copy/ar";
import { useCreateUser, useUpdateUser, useUsers } from "@/lib/dashboard/admin";
import { applyServerErrors } from "@/lib/forms/server-errors";
import { formatDateTime } from "@/lib/format/date";

import { EmptyBlock, ErrorBlock, LoadingBlock, PageHeader } from "../common/States";

const copy = ar.dashboard.users;
const ROLES: TenantUser["role"][] = ["owner", "manager", "sales", "warehouse", "accountant"];

/**
 * Users (08 §5): list, add with a role, change the role (BR-T06, D82), disable/enable and change password; the self,
 * last-user and last-owner refusals are shown.
 */
export function UsersPage() {
  const users = useUsers();
  const session = useDashboardSession();
  const [creating, setCreating] = useState(false);
  const [passwordFor, setPasswordFor] = useState<TenantUser | null>(null);
  const meId = session.data?.user.id;

  return (
    <>
      <PageHeader
        title={copy.title}
        description={copy.description}
        actions={<Button onClick={() => setCreating(true)}>{copy.add}</Button>}
      />
      {users.isError && <ErrorBlock error={users.error} onRetry={() => void users.refetch()} />}
      {users.isPending && <LoadingBlock rows={3} />}
      {users.data?.length === 0 && <EmptyBlock />}
      {users.data !== undefined && users.data.length > 0 && (
        <div className="overflow-x-auto rounded-md border">
          <Table stack>
            <TableHeader>
              <TableRow>
                <TableHead>{copy.name}</TableHead>
                <TableHead>{copy.email}</TableHead>
                <TableHead>{copy.role}</TableHead>
                <TableHead>{copy.status}</TableHead>
                <TableHead>{copy.lastLogin}</TableHead>
                <TableHead>{ar.dashboard.list.actions}</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {users.data.map((user) => (
                <UserRow key={user.id} user={user} isMe={user.id === meId} onPassword={() => setPasswordFor(user)} />
              ))}
            </TableBody>
          </Table>
        </div>
      )}
      {creating && <CreateUserDialog onClose={() => setCreating(false)} />}
      {passwordFor !== null && <PasswordDialog user={passwordFor} onClose={() => setPasswordFor(null)} />}
    </>
  );
}

function UserRow({ user, isMe, onPassword }: { user: TenantUser; isMe: boolean; onPassword: () => void }) {
  const update = useUpdateUser();
  const active = user.status === "active";

  return (
    <TableRow data-testid={`user-${user.id}`}>
      <TableCell className="font-medium">
        {user.name}
        {isMe && (
          <Badge variant="outline" className="ms-2">
            {copy.you}
          </Badge>
        )}
      </TableCell>
      <TableCell>
        <bdi>{user.email}</bdi>
      </TableCell>
      <TableCell>
        <NativeSelect
          aria-label={copy.roleOf(user.name)}
          value={user.role}
          disabled={update.isPending}
          onChange={(event) =>
            update.mutate(
              { id: user.id, role: event.target.value as TenantUser["role"] },
              { onSuccess: () => toast.success(copy.updated), onError: (failure) => toast.error(messageFor(failure)) },
            )
          }
        >
          {ROLES.map((role) => (
            <option key={role} value={role}>
              {copy.roles[role]}
            </option>
          ))}
        </NativeSelect>
      </TableCell>
      <TableCell>
        <Badge variant={active ? "default" : "secondary"}>{copy.statuses[user.status]}</Badge>
      </TableCell>
      <TableCell className="whitespace-nowrap">
        {user.last_login_at === null ? copy.never : formatDateTime(user.last_login_at)}
      </TableCell>
      <TableCell className="flex gap-1">
        <Button
          variant="ghost"
          size="sm"
          disabled={update.isPending}
          onClick={() =>
            update.mutate(
              { id: user.id, status: active ? "disabled" : "active" },
              { onSuccess: () => toast.success(copy.updated), onError: (failure) => toast.error(messageFor(failure)) },
            )
          }
        >
          {active ? <UserXIcon aria-hidden /> : <UserCheckIcon aria-hidden />}
          {active ? copy.disable : copy.enable}
        </Button>
        <Button variant="ghost" size="sm" onClick={onPassword}>
          <KeyRoundIcon aria-hidden />
          {copy.changePassword}
        </Button>
      </TableCell>
    </TableRow>
  );
}

const createSchema = z.object({
  name: z.string().trim().min(1, ar.validation.required).max(100),
  email: z.string().trim().email(ar.validation.email).max(190),
  password: z.string().min(8, copy.passwordHint).max(200),
  role: z.enum(["owner", "manager", "sales", "warehouse", "accountant"]),
});

type CreateValues = z.infer<typeof createSchema>;

export function CreateUserDialog({ onClose }: { onClose: () => void }) {
  const create = useCreateUser();
  const [error, setError] = useState<string | null>(null);
  const form = useForm<CreateValues>({
    resolver: zodResolver(createSchema),
    defaultValues: { name: "", email: "", password: "", role: "sales" },
  });
  const errors = form.formState.errors;
  const role = useWatch({ control: form.control, name: "role" });

  const submit = form.handleSubmit((values) => {
    setError(null);
    create.mutate(values, {
      onSuccess: () => {
        toast.success(copy.created);
        onClose();
      },
      onError: (failure) => {
        if (!applyServerErrors(failure, ["name", "email", "password", "role"], form.setError)) {
          setError(messageFor(failure));
        }
      },
    });
  });

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{copy.addTitle}</DialogTitle>
        </DialogHeader>
        <form onSubmit={submit} noValidate className="flex flex-col gap-3">
          <TextField id="user-name" label={copy.name} registration={form.register("name")} error={errors.name} />
          <TextField
            id="user-email"
            label={copy.email}
            type="email"
            dir="ltr"
            registration={form.register("email")}
            error={errors.email}
          />
          <TextField
            id="user-password"
            label={copy.password}
            type="password"
            dir="ltr"
            autoComplete="new-password"
            registration={form.register("password")}
            error={errors.password}
          />
          <div className="flex flex-col gap-1">
            <FieldLabel htmlFor="user-role">{copy.role}</FieldLabel>
            <NativeSelect id="user-role" {...form.register("role")}>
              {ROLES.map((value) => (
                <option key={value} value={value}>
                  {copy.roles[value]}
                </option>
              ))}
            </NativeSelect>
            <p className="text-muted-foreground text-xs">{copy.roleHints[role]}</p>
          </div>
          {error !== null && <FieldError role="alert">{error}</FieldError>}
          <DialogFooter>
            <Button type="button" variant="outline" onClick={onClose}>
              {ar.cancel}
            </Button>
            <Button type="submit" disabled={create.isPending}>
              {create.isPending ? ar.saving : ar.save}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

function PasswordDialog({ user, onClose }: { user: TenantUser; onClose: () => void }) {
  const update = useUpdateUser();
  const [error, setError] = useState<string | null>(null);
  const form = useForm<{ password: string }>({
    resolver: zodResolver(z.object({ password: z.string().min(8, copy.passwordHint).max(200) })),
    defaultValues: { password: "" },
  });

  const submit = form.handleSubmit(({ password }) =>
    update.mutate(
      { id: user.id, password },
      {
        onSuccess: () => {
          toast.success(copy.updated);
          onClose();
        },
        onError: (failure) => setError(messageFor(failure)),
      },
    ),
  );

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>
            {copy.changePassword}: {user.name}
          </DialogTitle>
        </DialogHeader>
        <form onSubmit={submit} noValidate className="flex flex-col gap-3">
          <TextField
            id="user-new-password"
            label={copy.newPassword}
            type="password"
            dir="ltr"
            autoComplete="new-password"
            registration={form.register("password")}
            error={form.formState.errors.password}
          />
          {error !== null && <FieldError role="alert">{error}</FieldError>}
          <DialogFooter>
            <Button type="button" variant="outline" onClick={onClose}>
              {ar.cancel}
            </Button>
            <Button type="submit" disabled={update.isPending}>
              {ar.save}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
