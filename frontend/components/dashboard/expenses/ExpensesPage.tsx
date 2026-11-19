"use client";

import { type FormEvent, useState } from "react";
import { toast } from "sonner";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Field, FieldDescription, FieldError, FieldLabel } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { NativeSelect } from "@/components/ui/native-select";
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from "@/components/ui/sheet";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import type { Expense, ExpenseCategory, ExpenseCategoryOption } from "@/lib/api/types";
import { ar, messageFor } from "@/lib/copy/ar";
import {
  EXPENSE_LIST_KEYS,
  useExpenseCategories,
  useExpenses,
  useRecordExpense,
  useVoidExpense,
} from "@/lib/dashboard/expenses";
import { activeFilters, hasFilters } from "@/lib/dashboard/list-query";
import { cairoToday } from "@/lib/dashboard/report-range";
import { useListQuery } from "@/lib/dashboard/use-list-query";
import { formatMoney } from "@/lib/format/money";

import { FilterPanel } from "../common/FilterPanel";
import { Pager } from "../common/Pager";
import { ReasonDialog } from "../common/ReasonDialog";
import { EmptyBlock, ErrorBlock, LoadingBlock, PageHeader } from "../common/States";

const copy = ar.dashboard.expenses;
const MONEY = /^\d{1,8}(\.\d{1,2})?$/;

/** D100: the expenses, voided ones marked, with the live total of the filter; record one, void one. */
export function ExpensesPage() {
  const [query, setQuery] = useListQuery(EXPENSE_LIST_KEYS);
  const expenses = useExpenses(query);
  const categories = useExpenseCategories();
  const [recording, setRecording] = useState(false);

  return (
    <>
      <PageHeader
        title={copy.title}
        description={copy.description}
        actions={<Button onClick={() => setRecording(true)}>{copy.record}</Button>}
      />
      <FilterPanel
        active={activeFilters(query, ["from", "to", "category", "voided"])}
        onClear={
          hasFilters(query) ? () => setQuery(Object.fromEntries(Object.keys(query).map((key) => [key, null]))) : undefined
        }
      >
        <label className="flex flex-col gap-1 text-xs">
          {ar.dashboard.list.from}
          <Input type="date" value={query.from ?? ""} onChange={(event) => setQuery({ from: event.target.value })} />
        </label>
        <label className="flex flex-col gap-1 text-xs">
          {ar.dashboard.list.to}
          <Input type="date" value={query.to ?? ""} onChange={(event) => setQuery({ to: event.target.value })} />
        </label>
        <NativeSelect
          aria-label={copy.category}
          value={query.category ?? ""}
          onChange={(event) => setQuery({ category: event.target.value || null })}
        >
          <option value="">{copy.allCategories}</option>
          {(categories.data ?? []).map((category) => (
            <option key={category.code} value={category.code}>
              {category.label}
            </option>
          ))}
        </NativeSelect>
        <NativeSelect
          aria-label={copy.status}
          value={query.voided ?? ""}
          onChange={(event) => setQuery({ voided: event.target.value || null })}
        >
          {Object.entries(copy.statuses).map(([value, label]) => (
            <option key={value} value={value}>
              {label}
            </option>
          ))}
        </NativeSelect>
      </FilterPanel>
      {expenses.data !== undefined && (
        <p className="text-sm font-semibold" data-testid="expenses-total">
          {copy.total(formatMoney(expenses.data.meta.total_amount))}
        </p>
      )}
      {expenses.isError && <ErrorBlock error={expenses.error} onRetry={() => void expenses.refetch()} />}
      {expenses.isPending && <LoadingBlock />}
      {expenses.data?.data.length === 0 && <EmptyBlock>{copy.none}</EmptyBlock>}
      {expenses.data !== undefined && expenses.data.data.length > 0 && (
        <div className="overflow-x-auto rounded-md border">
          <Table stack>
            <TableHeader>
              <TableRow>
                <TableHead>{copy.spentOn}</TableHead>
                <TableHead>{copy.category}</TableHead>
                <TableHead>{copy.amount}</TableHead>
                <TableHead>{copy.note}</TableHead>
                <TableHead>{ar.dashboard.list.actions}</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {expenses.data.data.map((expense) => (
                <ExpenseRow key={expense.id} expense={expense} />
              ))}
            </TableBody>
          </Table>
        </div>
      )}
      <Pager meta={expenses.data?.meta} onPage={(page) => setQuery({ page })} />
      {recording && <RecordExpenseSheet categories={categories.data ?? []} onClose={() => setRecording(false)} />}
    </>
  );
}

function ExpenseRow({ expense }: { expense: Expense }) {
  const voidExpense = useVoidExpense();
  const voided = expense.voided_at !== null;

  return (
    <TableRow data-testid={`expense-${expense.id}`}>
      <TableCell className="whitespace-nowrap">
        <bdi>{expense.spent_on}</bdi>
      </TableCell>
      <TableCell>{expense.category_label}</TableCell>
      <TableCell className={voided ? "text-muted-foreground whitespace-nowrap line-through" : "whitespace-nowrap"}>
        {formatMoney(expense.amount)}
      </TableCell>
      <TableCell className="max-w-64">{expense.note ?? ar.dashboard.none}</TableCell>
      <TableCell>
        {voided ? (
          <div className="flex flex-col items-start gap-1">
            <Badge variant="secondary">{copy.voidedBadge}</Badge>
            {expense.void_reason !== null && (
              <span className="text-muted-foreground max-w-48 text-xs">{expense.void_reason}</span>
            )}
          </div>
        ) : (
          <ReasonDialog
            trigger={(open) => (
              <Button variant="ghost" size="sm" className="text-destructive" onClick={open}>
                {copy.void}
              </Button>
            )}
            title={copy.voidTitle}
            description={copy.voidBody}
            confirmLabel={copy.void}
            destructive
            onSubmit={async (reason) => {
              await voidExpense.mutateAsync({ id: expense.id, reason });
              toast.success(copy.voided);
            }}
          />
        )}
      </TableCell>
    </TableRow>
  );
}

function RecordExpenseSheet({ categories, onClose }: { categories: ExpenseCategoryOption[]; onClose: () => void }) {
  return (
    <Sheet open onOpenChange={(open) => !open && onClose()}>
      <SheetContent side="end" className="w-full overflow-y-auto sm:max-w-md">
        <SheetHeader>
          <SheetTitle>{copy.recordTitle}</SheetTitle>
          <SheetDescription>{copy.goodsHint}</SheetDescription>
        </SheetHeader>
        <div className="px-4 pb-4">
          <ExpenseForm categories={categories} onDone={onClose} />
        </div>
      </SheetContent>
    </Sheet>
  );
}

type Problems = { spentOn?: string; amount?: string; category?: string; note?: string };

/** D100 a: day (today by default, never after it), category, amount and note (required for «أخرى»). */
export function ExpenseForm({ categories, onDone }: { categories: ExpenseCategoryOption[]; onDone: () => void }) {
  const record = useRecordExpense();
  const today = cairoToday();
  const [spentOn, setSpentOn] = useState(today);
  const [category, setCategory] = useState<ExpenseCategory | "">("");
  const [amount, setAmount] = useState("");
  const [note, setNote] = useState("");
  const [problems, setProblems] = useState<Problems>({});
  const [failure, setFailure] = useState<unknown>(null);
  const needsNote = categories.find((option) => option.code === category)?.needs_note ?? false;

  const submit = (event: FormEvent) => {
    event.preventDefault();
    const next: Problems = {};

    if (!/^\d{4}-\d{2}-\d{2}$/.test(spentOn) || spentOn > today) {
      next.spentOn = copy.invalidDate;
    }

    if (!MONEY.test(amount.trim()) || Number(amount) <= 0) {
      next.amount = copy.invalidAmount;
    }

    if (category === "") {
      next.category = copy.chooseCategory;
    }

    if (needsNote && note.trim() === "") {
      next.note = copy.noteRequired;
    }

    setProblems(next);
    setFailure(null);

    if (Object.keys(next).length > 0 || category === "") {
      return;
    }

    record.mutate(
      { spent_on: spentOn, amount: amount.trim(), category, note: note.trim() || null },
      {
        onSuccess: () => {
          toast.success(copy.recorded);
          onDone();
        },
        onError: setFailure,
      },
    );
  };

  return (
    <form onSubmit={submit} noValidate className="flex flex-col gap-4">
      <Field data-invalid={problems.spentOn !== undefined}>
        <FieldLabel htmlFor="expense-day">{copy.spentOn}</FieldLabel>
        <Input
          id="expense-day"
          type="date"
          max={today}
          className="h-11"
          value={spentOn}
          onChange={(event) => setSpentOn(event.target.value)}
        />
        {problems.spentOn !== undefined && <FieldError>{problems.spentOn}</FieldError>}
      </Field>
      <Field data-invalid={problems.category !== undefined}>
        <FieldLabel htmlFor="expense-category">{copy.category}</FieldLabel>
        <NativeSelect
          id="expense-category"
          value={category}
          onChange={(event) => setCategory(event.target.value as ExpenseCategory | "")}
        >
          <option value="">{copy.chooseCategory}</option>
          {categories.map((option) => (
            <option key={option.code} value={option.code}>
              {option.label}
            </option>
          ))}
        </NativeSelect>
        <FieldDescription>{copy.goodsHint}</FieldDescription>
        {problems.category !== undefined && <FieldError>{problems.category}</FieldError>}
      </Field>
      <Field data-invalid={problems.amount !== undefined}>
        <FieldLabel htmlFor="expense-amount">{copy.amount}</FieldLabel>
        <Input
          id="expense-amount"
          dir="ltr"
          inputMode="decimal"
          className="h-11 text-start"
          value={amount}
          onChange={(event) => setAmount(event.target.value)}
        />
        <FieldDescription>{ar.currency}</FieldDescription>
        {problems.amount !== undefined && <FieldError>{problems.amount}</FieldError>}
      </Field>
      <Field data-invalid={problems.note !== undefined}>
        <FieldLabel htmlFor="expense-note">{needsNote ? copy.note : copy.noteOptional}</FieldLabel>
        <Input
          id="expense-note"
          className="h-11"
          maxLength={200}
          value={note}
          onChange={(event) => setNote(event.target.value)}
        />
        {problems.note !== undefined && <FieldError>{problems.note}</FieldError>}
      </Field>
      {failure !== null && <FieldError role="alert">{messageFor(failure)}</FieldError>}
      <Button type="submit" className="h-11" disabled={record.isPending}>
        {record.isPending ? ar.saving : ar.save}
      </Button>
    </form>
  );
}
