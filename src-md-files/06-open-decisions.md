# BelGomla E-commerce — Open Decisions (Product Owner Input Required)

Each item below has a **default** already written into the other documents so work can proceed. If the product owner disagrees, the referenced rule is updated.

| #   | Decision                                                   | Default applied                                                                                               | Where       |
| --- | ---------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------- | ----------- |
| D5  | Basic V1 dashboard reports                                 | (a) Orders per status and total sales by date range. (b) Outstanding amount per customer. (c) Low-stock SKUs. | Roadmap §10 |
| D6  | Customer-side in-app notifications                         | None in V1. Customer sees status on the "my orders" page only.                                                | BR-N02      |
| D7  | Cancelling an order that has collections                   | Blocked until collections are voided.                                                                         | BR-O03      |
| D9  | Can a customer change the delivery address after ordering? | No. Merchant can edit the address snapshot on the order before `Out for Delivery`.                            | BR-O10      |
| D10 | Highlight threshold for stale Pending orders               | 24 hours.                                                                                                     | BR-INV07    |
| D16 | Final revision edition modelling                           | Separate product + SKU linked to the main book via `revision_of`. Not a variant.                              | BR-P10      |
| D17 | School-book template fields                                | grade, subject, publisher, term, track, edition_type, academic_year (all select, filterable).                 | BR-P09      |
| D18 | Storefront facet order for school books                    | Grade → Subject → Publisher, then the rest.                                                                   | BR-P08      |
| D19 | Season rollover tooling in V1                              | Duplicate product + bulk status change by filter. Full import/export stays V2.                                | BR-P11/P12  |
| D21 | Pre-orders on Coming Soon products                         | V1: Coming Soon is visible but not orderable. Pre-ordering is V2.                                             | BR-P04      |
| D22 | Cover price + % pricing                                    | Added to V1 (BR-PR10, BR-PR11). Pricing engine still stores absolute prices.                                  | BR-PR10     |
| D23 | Printable price list                                       | Added to V1 as a print-friendly page. Excel/PDF export is V2.                                                 | BR-P13      |
| D24 | Credit terms (آجل) per customer                            | V2. In V1 the merchant tracks outstanding balance through collection records and the per-customer report.     | Roadmap V2  |
| D25 | Product without a category                                 | Not allowed: `category_id` is required (any level of the tree).                                               | BR-P06      |
| D26 | Gallery size                                               | At most 8 images per product, png/jpg/webp, 2 MB each. The first image becomes the main image.               | BR-P07      |
| D29 | Bulk status hits a product whose transition is not allowed | The product is skipped and counted; the response is `{ affected, skipped, batch_id }`. An empty filter is refused (422). | BR-P12 |
| D30 | Duplicate product naming                                   | Same name, new slug (`-2`, `-3`), SKU code `{code}-COPY`, `-COPY-2`, …; status `inactive`; images, relations and stock not copied. | BR-P11 |
| D31 | Products inside an archived category                       | Stay visible on the store (product status alone decides); the archived category and its subtree leave the store category tree. | BR-P06 |
| D32 | Arabic search normalisation                                | Remove tashkeel/tatweel; أ إ آ ٱ → ا, ى → ي, ة → ه; Egyptian colloquial ث → ت, ذ → د, ظ → ض; Latin lower-cased. Every query word must appear in name + SKU code + select option labels. | BR-P08 |
| D33 | Stationery / hardware templates                            | Filterable selects: stationery = brand, color (6 common colours), size; hardware = brand, material, size. Options other than colour start empty. | BR-P09 |

These are treated as accepted defaults unless the product owner objects.

## Resolved by the product owner (2026-10-09)

| Old # | Decision                  | Resolution                                                                                                           | Where                |
| ----- | ------------------------- | -------------------------------------------------------------------------------------------------------------------- | -------------------- |
| D1    | Public prices             | Public by default; tenant setting `require_login_for_prices` can hide them.                                          | BR-CU04, BR-M        |
| D2    | Customer approval         | Off by default; tenant setting `require_customer_approval` adds `Pending Approval` status.                           | BR-CU04/CU05, BR-M   |
| D3    | VAT / taxes               | Merchant prices are final; no VAT, no tax engine in V1.                                                              | BR-PR09              |
| D11   | Messaging provider        | **Superseded 2026-10-09:** Hypersender (hypersender.com) for WhatsApp and SMS behind the `MessageDriver` contract, replacing Meta Cloud API + SMS Misr. Meta Cloud remains the migration path if the unofficial channel is blocked. | Tech §13 |
| D12   | WhatsApp number           | One dedicated platform number = one Hypersender instance for all tenants in V1. Never a merchant's personal number.   | Tech §13             |
| D13   | Messaging cost            | Platform pays: Hypersender Starter after the 14-day trial (≈ $9.99/instance/month) plus carrier SMS cost on the platform SIM; usage logged per tenant. | Tech §13 |
| D46   | Unofficial WhatsApp risk  | Accepted for V1: Hypersender is not an official WhatsApp API and its docs say a block is possible. Mitigation: transactional only, ≤ 10 msgs/min, dedicated number, SMS fallback, driver swap to Meta Cloud if blocked. | Tech §13 |
| D14   | Custom field types        | text, number, select, boolean.                                                                                       | BR-P08               |
| D15   | Self-registered ordering  | Can order immediately unless `require_customer_approval` is on.                                                      | BR-CU04              |
| D20   | Returns (المرتجع)         | **Not in V1.** Planned for V2 as the first V2 increment. Schema reserves `return` movement type and `order_returns`. | BR-INV06, Roadmap V2 |
| D4    | Category depth            | Unlimited nesting; any category can have sub-categories.                                                             | BR-P06               |
| D8    | Phone verification        | OTP via WhatsApp, automatic SMS fallback if the number is not on WhatsApp.                                           | BR-CU07, Tech §13    |
| new   | Merchant creates customer | Merchant can create a customer and send an invitation link via WhatsApp/SMS.                                         | BR-CU08              |
| new   | Custom product fields     | Tenant-defined custom fields (e.g. Brand) on products.                                                               | BR-P08               |

## Already resolved during review (no input needed)

See `04-technical-constraints.md` §19.
