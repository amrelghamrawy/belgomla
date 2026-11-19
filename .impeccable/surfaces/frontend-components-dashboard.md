---
version: 1
slug: "frontend-components-dashboard"
primary_target: "frontend/components/dashboard"
related_targets: ["frontend/app/(dashboard)"]
---

# Surface brief: merchant dashboard

Scope: every page under `/dashboard` (shell, overview, lists, detail pages, reports, dialogs, login). Mode: **Operate**.
Audience and job: see PRODUCT.md (owner on phone + staff on desktop; orders, then stock, then collections).
Untouched: the storefront (D67) and its `.store-theme`; behaviour, routes, permissions.
History: the rolled direction "Carbon invoice book" (seed:79c6b48e11b299f3) was built and rejected by the product owner on 2026-11-19, who then pinned the Figma community file *Horizon UI Free* as the look. This brief records the pinned direction.
Open: BelGomla logo (none exists: text mark only), dark-mode switch (undecided).

## Direction contract

THESIS: The merchant's back office in the calm, airy admin language of the pinned reference: white panels floating on a lavender ground, so the day's numbers and the next action read first. It refuses the bordered grey shadcn default.

OWN-WORLD: Ground #f4f7fe, white borderless panels with 20px corners and one soft shadow, brand #422afb on navy ink #1b2559, soft-tint status badges, round icon tiles on stat cards, white sidebar with a brand bar on the open item, DM Sans figures over IBM Plex Sans Arabic.

STORY: In seconds the team sees today's figures and what needs action (warnings in words), opens the latest orders, and reads reports chart-first with the rows one click away.

FIRST VIEWPORT: White sidebar (wordmark «بالجملة», the store chip, sections) at the start edge; floating bar with breadcrumb and a white pill (order search, bell, avatar); page title and date with the primary action; six stat cards with icon tiles; latest orders beside top debtors.

FORM: seed: none (pinned) "النتيجه مش مرضيه خالص ... بص الفرق بين النتيجه بتاعتك وdeesign على فيجما" with the Figma community file Horizon UI Free (product owner, 2026-11-19).

FINISH: unreviewed and undocumented is unfinished; this build ends with the finish review, the verdict, DESIGN.md, and every shipping raster carrying its provenance
