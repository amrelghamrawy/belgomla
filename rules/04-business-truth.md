# 04 — Business Truth: the code is evidence, not the rule

The code tells you what the system does today. It does not tell you what the business needs. Pricing, stock, collections, returns and costing are where a wrong reading costs the most: a test written after the code, from the code, repeats the code's mistake and passes.

Source of truth, in order: `src-md-files/02-business-rules.md` (BR ids), the decisions in `src-md-files/06-open-decisions.md` (D ids), `07-domain-model.md`, `08-api-contract.md`, then the code. When they disagree, `rules/00` already says which wins; this file says how to read the code without letting it write the rules.

---

## 1. What a code finding is allowed to become

| You found in the code… | It may become… | It may never become… |
|---|---|---|
| Something that exists and works | reuse: call it, extend it | "the business works this way" |
| Something missing (a field, a unit, a state) | new work in the task | "the business does without it" |
| A bug | a fix with its own test, named in the reply | a new rule written around the bug |
| A gap we accept for now | a limitation, written as one in the task and `06` | a law of the domain |
| Existing rows | a migration that keeps their meaning | a reinterpretation of history |
| A column name, a label, a UI unit | nothing | a concept ("cost is per piece" because the column says so) |

When the spec is silent and the code answers, the code's answer is still only a **default**: record it in `06-open-decisions.md` with the default applied (`rules/00`), and say so in the reply.

## 2. A statement about today's code is traced, not grepped

Any sentence in a task file, a reply or a commit that says "today the code does X" carries its source as `path:line`. A grep hit is a lead. Before you build on it, open the function and answer all four:

1. **What does it write, exactly?** Which columns, and how: an increment, a recomputed total, an overwrite.
   (`collected_amount` is recomputed as Σ live payments − Σ live refunds: `Collections/Queries/CollectedAmountQuery.php`. It is not incremented.)
2. **When, and in which transaction?** Inside the caller's transaction, after commit, or in a job, and what happens on failure: rollback, log only, or a returned error.
3. **What does it look up, and by which key?** Status values, ids, the tenant scope, the unit (base unit or selling unit, V2 Task 13).
4. **Is the data actually there?** A column that exists is not a column that is filled. Find the writers of that column for this kind of row.

If one of the four has no answer, the sentence is written as "not checked", not as fact.

## 3. Before you change who writes a number

When a change touches a money, quantity or status column (`order_total`, `returned_total`, `collected_amount`, `on_hand`, `reserved`, `quantity_base`, any cost or price column, any `status`), list **every** current writer first and put the list in the reply:

```bash
grep -rn "'<column>'\|-><column> =\|<column>' =>" backend/app --include=*.php          # Eloquent writes
grep -rn "increment('<column>'\|decrement('<column>'" backend/app --include=*.php
grep -rn "update(\[\|->update(\|DB::table(" backend/app/Modules/<Module> --include=*.php  # mass and raw writes
```

Then read each hit (§2). A writer the change forgets is the usual cause of "the number is right on the new screen and wrong on the old report".

Where one class must be the only writer, an arch test says so (as `StockLedger` is for `sku_inventory` and `inventory_movements`, `tests/Arch/ModulesBoundariesTest.php`). A new single-writer rule gets the same kind of arch test in the same commit.

## 4. The expected value in a test comes from the spec

For every calculation (price, tier, discount, promotion, bundle, return value, balance, cost, report total):

- The expected number is worked out **from the BR/D text**, by hand, before running the code. Never run the code and paste its output into `toBe()`.
- The test name carries the rule id; the dataset case name or a one-line comment carries the arithmetic:

```php
test('BR-C03 collected amount is live payments minus live refunds', function () {
    // 500.00 + 300.00 paid, 120.00 refunded, the 50.00 payment voided → 800.00 − 120.00 = 680.00
    ...
    expect($order->refresh()->collected_amount)->toBe('680.00');
});
```

- At least one case per calculation sits on a boundary the rule names (tier minimum − 1 and = minimum, an amount exactly equal to the balance, a quantity exactly equal to stock) and one case uses numbers where a wrong formula would give a different answer (not 1 × 100).
- When the hand-worked number and the code disagree, stop: either the code is wrong (fix it) or the spec is ambiguous (record it in `06` and ask). Changing the expected value to match the code is not an option.

## 5. Existing data

A change to how a stored number or status is computed, in a module that already has rows, states in the task and the reply what happens to those rows: recomputed by a migration (which one, idempotent), kept as they were (and why that is correct), or out of scope (and who sees the inconsistency). "New rows only" is a decision, so it goes into `06`.

## 6. In the reply that calls a feature done

On top of `rules/01` §6 (test delta, rule ids):

- the "today the code does" statements you relied on, each with its `path:line`;
- the writer list from §3, when the change touched who writes a number;
- every default you took where the spec was silent, with its new D id.
