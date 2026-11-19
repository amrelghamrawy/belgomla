---
name: BelGomla Dashboard
description: The merchant dashboard in BelGomla's own platform identity, built on the Horizon UI Free reference, the same for every merchant (D104).
colors:
  brand-indigo: "#422afb"
  brand-indigo-deep: "#3311db"
  brand-mist: "#f2efff"
  lavender-ground: "#f4f7fe"
  panel-white: "#ffffff"
  navy-ink: "#1b2559"
  slate-ink: "#5f6c94"
  hairline: "#e9edf7"
  field-line: "#e0e5f2"
  row-hover: "#f8faff"
  ok: "#017a52"
  ok-soft: "#e6faf5"
  warn: "#9a5800"
  warn-soft: "#fff6da"
  info: "#2a50db"
  info-soft: "#eff4fb"
  danger: "#c81414"
  danger-soft: "#feefee"
typography:
  display:
    fontFamily: "DM Sans, IBM Plex Sans Arabic, system-ui, sans-serif"
    fontSize: "2.125rem"
    fontWeight: 700
    lineHeight: 1.25
    letterSpacing: "0em"
  display-phone:
    fontFamily: "DM Sans, IBM Plex Sans Arabic, system-ui, sans-serif"
    fontSize: "1.75rem"
    fontWeight: 700
    lineHeight: 1.25
    letterSpacing: "0em"
  figure:
    fontFamily: "DM Sans, IBM Plex Sans Arabic, system-ui, sans-serif"
    fontSize: "1.4rem"
    fontWeight: 700
    lineHeight: 1.25
    fontFeature: "tnum"
  title:
    fontFamily: "DM Sans, IBM Plex Sans Arabic, system-ui, sans-serif"
    fontSize: "1.125rem"
    fontWeight: 700
    lineHeight: 1.5
  nav:
    fontFamily: "DM Sans, IBM Plex Sans Arabic, system-ui, sans-serif"
    fontSize: "0.95rem"
    fontWeight: 500
    lineHeight: 1.4
  body:
    fontFamily: "DM Sans, IBM Plex Sans Arabic, system-ui, sans-serif"
    fontSize: "0.875rem"
    fontWeight: 500
    lineHeight: 1.43
    fontFeature: "tnum"
  label:
    fontFamily: "DM Sans, IBM Plex Sans Arabic, system-ui, sans-serif"
    fontSize: "0.75rem"
    fontWeight: 500
    lineHeight: 1.33
rounded:
  badge: "10px"
  control-sm: "12px"
  control: "14px"
  bar: "16px"
  panel: "20px"
  pill: "9999px"
spacing:
  xs: "4px"
  sm: "8px"
  md: "12px"
  lg: "16px"
  xl: "20px"
  2xl: "24px"
  gutter-phone: "16px"
  gutter-desk: "28px"
components:
  button-primary:
    backgroundColor: "{colors.brand-indigo}"
    textColor: "{colors.panel-white}"
    rounded: "{rounded.control}"
    padding: "0 17.6px"
    height: "40px"
  button-primary-hover:
    backgroundColor: "{colors.brand-indigo-deep}"
  button-outline:
    backgroundColor: "{colors.panel-white}"
    textColor: "{colors.navy-ink}"
    rounded: "{rounded.control}"
    height: "40px"
  button-soft:
    backgroundColor: "{colors.brand-mist}"
    textColor: "{colors.brand-indigo}"
    rounded: "{rounded.control}"
    height: "40px"
  input:
    backgroundColor: "{colors.panel-white}"
    textColor: "{colors.navy-ink}"
    rounded: "{rounded.control}"
    padding: "0 14.4px"
    height: "40px"
  search-pill:
    backgroundColor: "{colors.lavender-ground}"
    textColor: "{colors.navy-ink}"
    rounded: "{rounded.pill}"
    height: "40px"
    width: "256px"
  tab-active:
    backgroundColor: "{colors.brand-indigo}"
    textColor: "{colors.panel-white}"
    rounded: "{rounded.control-sm}"
    height: "40px"
  tab-idle:
    textColor: "{colors.slate-ink}"
    rounded: "{rounded.control-sm}"
    height: "40px"
  panel:
    backgroundColor: "{colors.panel-white}"
    textColor: "{colors.navy-ink}"
    rounded: "{rounded.panel}"
    padding: "16px 20px"
  stat-icon-tile:
    backgroundColor: "{colors.lavender-ground}"
    textColor: "{colors.brand-indigo}"
    rounded: "{rounded.pill}"
    size: "56px"
  badge-warn:
    backgroundColor: "{colors.warn-soft}"
    textColor: "{colors.warn}"
    rounded: "{rounded.badge}"
    height: "22.4px"
  badge-info:
    backgroundColor: "{colors.info-soft}"
    textColor: "{colors.info}"
    rounded: "{rounded.badge}"
    height: "22.4px"
  badge-primary:
    backgroundColor: "{colors.brand-mist}"
    textColor: "{colors.brand-indigo}"
    rounded: "{rounded.badge}"
    height: "22.4px"
  badge-ok:
    backgroundColor: "{colors.ok-soft}"
    textColor: "{colors.ok}"
    rounded: "{rounded.badge}"
    height: "22.4px"
  badge-danger:
    backgroundColor: "{colors.danger-soft}"
    textColor: "{colors.danger}"
    rounded: "{rounded.badge}"
    height: "22.4px"
  nav-item-active:
    textColor: "{colors.navy-ink}"
    height: "44px"
  nav-item:
    textColor: "{colors.slate-ink}"
    height: "44px"
---

# Design System: BelGomla Dashboard

## Overview

**Creative North Star: "The Daylight Back Office"**

The dashboard is a bright, calm workroom: white panels floating on a cool lavender ground, one saturated indigo that marks where you are and what to press, and navy ink for everything worth reading. It is BelGomla's platform identity (D104), worn the same way by every merchant; the tenant contributes only its name and logo in the sidebar and top bar, never its colours. The look is taken from the MIT-licensed Figma community file *Horizon UI Free* (Chakra source: horizon-ui/horizon-ui-chakra), which the product owner pinned as the reference after a first "carbon invoice book" direction was built and discarded.

Density is moderate and even: 40px controls, 20px panel corners, 16px to 24px gaps. The same screens serve an owner on a phone and staff at a desk, so lists turn into stacked cards below 768px and the first column of a wide table stays put while the rest scrolls. Reports lead with a chart and fold their long tables underneath. Every warning is said in words and an icon, not colour alone.

The system departs from Horizon in two deliberate places. The muted ink and the state inks are darker than the reference so they pass WCAG AA on their tints. Headings keep zero letter-spacing because negative tracking breaks Arabic letter joining. The storefront is out of scope: it keeps its own D67 identity under `.store-theme` and is not described here.

**Key Characteristics:**
- Cool lavender ground, white borderless panels, one soft ambient shadow.
- A single brand hue (indigo) for the active place, the primary action, chart marks and links.
- Navy ink for text and figures; slate ink for labels, meta and axes.
- Soft-tint status badges, each a light wash with a readable ink of the same hue.
- DM Sans for Latin and figures, IBM Plex Sans Arabic for Arabic, tabular numbers in tables.
- Phone-ready lists (`<Table stack>`) and chart-first reports.

## Colors

A cool, almost monochrome blue-violet field with one electric indigo accent and four quiet status washes.

### Primary
- **Brand Indigo** (#422afb): the primary button, the active tab and active period preset, the sidebar's active bar and active icon, links ("عرض الكل"), the avatar button, stat-card icons, and every single-series chart mark (columns, line, BarList fill). Also the focus ring and the focused field's border.
- **Deep Indigo** (#3311db): the primary button on hover; nothing else.
- **Indigo Mist** (#f2efff): text selection, the soft button, the "preparing" badge, the tenant initial's disc, and the hover wash under a DetailsBlock summary.

### Neutral
- **Lavender Ground** (#f4f7fe): the page behind everything, and also the muted fill: idle tab hover, the search pill, the stat-card icon tile, empty bar tracks, the tenant card in the sidebar.
- **Panel White** (#ffffff): panels, tables, tab bars, the sidebar, the top bar's tool pill, dialogs, popovers, fields and outline buttons.
- **Navy Ink** (#1b2559): all text, headings and figures; table cells; the active nav item.
- **Slate Ink** (#5f6c94): labels, table heads, descriptions, breadcrumb, idle tabs, idle nav items, chart ticks, the small currency suffix and dimmed zero amounts.
- **Hairline** (#e9edf7): table header rule, card-header rule, chart grid and axis line; table row rules at 60% of it.
- **Field Line** (#e0e5f2): the 1px border of fields and outline buttons; the scrollbar thumb.
- **Row Hover** (#f8faff): a table row under the pointer (including its sticky first cell).

### State
- **Success Green** (#017a52) on **Mint** (#e6faf5): delivered, collected, a good KPI change.
- **Amber Ink** (#9a5800) on **Cream** (#fff6da): pending, partly collected, stale; a stat card that needs action turns its icon tile cream with an amber icon and prints «يحتاج متابعة» with a warning glyph.
- **Info Blue** (#2a50db) on **Ice** (#eff4fb): confirmed.
- **Signal Red** (#c81414) on **Blush** (#feefee): cancelled, unpaid, overdue amounts, a bad KPI change, returns in the sales chart, the notification count. The aging ramp is this red at 30%, 50%, 75% and 100% by age, with "not yet due" in slate ink at 35%.

### Named Rules
**The One Hue Rule.** Indigo is the only accent. Charts with one series use it alone; a second series is either slate (previous period, dashed) or red (returns). The extra chart tokens defined in the theme (sky, violet, coral) are not used and need a decision before they are.

**The Tint-and-Ink Rule.** A status is a pale wash with a dark ink of the same hue and a word inside it. "On the road" is the one solid badge (indigo with white), because it is the state in motion.

**The Platform Not Tenant Rule.** The dashboard's colours come from `.dash-theme` only. A tenant's brand colours never reach the dashboard; its logo and name do.

## Typography

**Display Font:** DM Sans (with IBM Plex Sans Arabic, then system-ui)
**Body Font:** the same stack; Arabic text always renders in IBM Plex Sans Arabic
**Label/Mono Font:** none distinct

**Character:** A geometric, friendly Latin face for figures and codes, paired with a clear humanist Arabic. The stack names "DM Sans" directly instead of next/font's variable, because that variable carries an Arial-based fallback whose Arabic glyphs would win over IBM Plex Sans Arabic.

### Hierarchy
- **Display** (700, 1.75rem on phones, 2.125rem from 768px, line-height 1.25): the page title in the page header, on the ground.
- **Wordmark** (700, 1.65rem, line-height 1): «بالجملة» heading the sidebar.
- **Figure** (700, 1.4rem, tabular): stat-card and KPI values; KPI values drop to 1.125rem on phones.
- **Title** (700, 1.125rem): panel titles.
- **Nav** (500, 0.95rem; 700 when active): sidebar items.
- **Body** (500, 0.875rem, tabular in tables): table cells, lists, BarList rows. The first cell of a table row is 700.
- **Label** (500, 0.75rem): table heads, KPI labels, hints, chart ticks (11px), stacked-card field labels (0.72rem).

### Named Rules
**The Flat Tracking Rule.** No negative letter-spacing anywhere; it breaks Arabic joining. Horizon's tight headings are reproduced with weight and size only.

**The Exact Money Rule.** Amounts in tables and lists use the Money component: tabular figures, the currency «ج.م» at 0.75em in slate, and a zero amount dimmed to slate at normal weight, so the rows that carry money stand out. The text stays «1,250.50 ج.م» for screen readers and copy.

## Layout

The shell is a white sidebar on the start side (the right in RTL), 17.5rem wide, collapsible to icons on wide screens and a drawer below 768px. Beside it, a sticky floating top bar: on wide screens the sidebar toggle and the breadcrumb («لوحة التحكم / القسم») at the start, and at the end a white pill holding the order-search pill (224px, 256px from 1024px), the bell and the indigo avatar. On phones the breadcrumb gives way to the tenant's logo and name, and the search pill hides. The bar's own background is the ground at 80% with a backdrop blur.

Content sits in `<main>`, capped at 1600px, with 16px side gutters on phones and 28px from 768px, 24px between page sections. Card grids use 16px gaps: stat cards run one column, two from 640px, three from 1280px; overview and report panels pair or split two-to-one from 1024px. Panels pad 16px by 20px.

Lists marked `<Table stack>` become, below 768px, a column of white cards 12px apart: the first cell heads the card across its full width at 1rem, the remaining cells sit two to a row as label-over-value pairs (labels are copied from the column headers), and a cell holding buttons spans the card's foot with its buttons sharing the width. Wider than that, a table is a white panel whose first column is sticky while the rest scrolls sideways.

Reports open with a toolbar (a segmented period control, date range, group and filter selects in a white filter panel, export buttons) and then lead with KPI tiles and charts; a long table sits folded beneath its chart in a DetailsBlock.

## Elevation & Depth

Depth is one ambient shadow, never a border. Panels, the sidebar, tab bars, the top bar's tool pill, segmented controls, the filter panel, KPI tiles and empty states all float on the lavender ground with the same soft, wide, cool shadow, its x offset mirrored from Horizon's for RTL so light falls from the start side. Nothing nested inside a panel gets its own shadow: a table inside a card, dialog or sheet drops its panel treatment.

### Shadow Vocabulary
- **Panel** (`box-shadow: -14px 17px 40px 4px rgb(112 144 176 / 0.08)`): every resting surface on the ground.
- **Menu** (`box-shadow: -14px 17px 40px 4px rgb(112 144 176 / 0.18)`): popovers and dropdown menus.
- **Dialog** (`box-shadow: 0 24px 60px -12px rgb(27 37 89 / 0.25)`): dialogs and alert dialogs.
- **Active tab glow** (`box-shadow: 0 8px 18px -8px color-mix(in srgb, #422afb 70%, transparent)`): under the open tab only.

### Named Rules
**The Borderless Panel Rule.** Panels have no border. Separation comes from the white-on-lavender step and the panel shadow; hairlines appear only inside a panel (header rule, row rules).

**The Lift-on-Hover Rule.** A stat card that is a link rises 2px on hover; nothing else moves.

## Shapes

Generously rounded, never sharp. Panels, tables, stacked list cards, dialogs and empty states have 20px corners. Controls (fields, default buttons, selects, textareas) have 14px; small buttons and tabs 12px; large buttons, alerts, tab bars and popovers 16px; badges 10px. Round things are fully round: the stat-card icon tile (56px), the avatar, the bell, the sidebar toggle, the search pill, the tool pill, bar tracks, the sidebar's active bar (4px by 36px with round ends) and chart legend dots. Column charts cap each bar with a 6px (period columns) or 4px (sales chart) top radius.

## Components

### Buttons
Confident and compact.
- **Shape:** gently rounded (14px), 40px tall, 600 weight; small 34px with 12px corners, large 44px with 16px.
- **Primary:** brand indigo with white text, deepening to #3311db on hover.
- **Outline:** white with a field-line border, navy text.
- **Soft (secondary):** indigo mist with indigo text.
- **Ghost:** transparent; used inside segmented controls (period presets, export) where the chosen one switches to primary.
- **Focus:** the shared ring in brand indigo.

### Status Badges
- **Style:** 22.4px tall, 10px corners, 700 weight, transparent border, soft tint with same-hue ink (see Colors). The label always names the state.
- **Order states:** pending amber, confirmed info blue, preparing indigo mist, out for delivery solid indigo, delivered green, cancelled red. **Collection states:** unpaid red, partly collected amber, collected green.
- **Outline variants:** expiry (red outline), pre-order (indigo outline), placed-by-merchant (neutral outline) carry a Hint.

### Cards / Containers
- **Corner Style:** 20px.
- **Background:** panel white on lavender ground.
- **Shadow Strategy:** the Panel shadow.
- **Border:** none; a header may carry a hairline rule below it.
- **Internal Padding:** 16px by 20px; titles at 1.125rem, 700.

### Stat Card
The overview's signature tile: a link panel with a 56px round icon tile (lavender with an indigo icon) beside a slate label over a navy figure. When the figure needs action the tile turns cream with an amber icon and a line «يحتاج متابعة» with a warning glyph appears under the figure; an optional slate hint sits beside it. Hover lifts it 2px.

### KPI Tile
A report headline number: a padded panel with a slate label, the figure, and the change against the previous period as an arrow icon plus text, green when good and red when bad (a rise is bad for returns and discounts), slate when flat.

### Inputs / Fields
- **Style:** white, 1px field-line border, 14px corners, 40px tall, 14.4px side padding.
- **Focus:** border turns brand indigo with a 3px indigo halo at 15%.
- **Search pill:** the top bar's order search is a borderless lavender pill with a leading search icon.

### Tabs
A white bar (16px corners, Panel shadow, 5.6px inner padding) of 40px pills with 12px corners. Idle tabs are slate, washing to lavender with navy text on hover; the open tab is solid brand indigo with white text and the active tab glow.

### Navigation
- **Sidebar:** white, Panel shadow, no border. It opens with the «بالجملة» wordmark, then the tenant card (lavender, logo or initial disc, name over «لوحة بالجملة»), a hairline, then grouped items under small bold slate group labels.
- **Items:** 44px tall, 20px icons, slate at 500. Hover turns the text navy with no fill. Active: navy text at 700, the icon in brand indigo, and a 4px by 36px indigo bar on the sidebar's inner edge. No active fill.
- **Collapsed:** icons with tooltips; the active bar hides. **Phones:** a drawer that closes when an item is picked.
- **Top bar:** see Layout. The bell shows an unread count in a red disc ringed in white.

### Row actions
Every button inside a table cell looks the same, whatever variant the page chose (one rule in `.dash-theme`): a 32px soft pill (44px on phones), 10px corners, no border, 13px at 700. Neutral actions (تعديل، أرشفة، إعادة تفعيل) sit on the lavender ground tint and turn indigo mist with indigo text on hover; the primary action starts in indigo mist and turns solid indigo; a destructive action (`variant="destructive"` or `text-destructive`, e.g. إلغاء التحصيل) is red mist with red ink and turns solid red with white text on hover. Icon-only actions are 32px squares. On stacked phone cards the actions share the card's width.

### Tables
White panels with 12px by 8px inner padding. Heads are quiet: transparent, slate, 0.75rem at 500, 44px tall, a hairline under the header row. Cells are navy at 500 with 13.6px by 12px padding; the first cell is 700 and sticky at the start edge. Rows hover to #f8faff. Numbers are tabular. On phones, `<Table stack>` turns rows into cards (see Layout).

### Charts
- **BarList:** a ranked list of horizontal bars, one hue, one shared scale from zero, 8px round tracks in lavender with an indigo fill (a muted item in slate at 40%); label and value as text above the bar, value at 600 tabular. Linked rows wash lavender on hover. Bars grow from the start edge.
- **PeriodColumns:** one money series as thin indigo columns (max 22px, 6px top radius), hairline horizontal grid, slate 11px ticks, compact value axis on the right, time reversed to run right to left; a rounded tooltip per column. Titled by its card, so no legend.
- **SalesTrendChart:** net sales as a 2px indigo line, the previous period as a 2px dashed slate line at 70%, a two-entry legend above, a slate crosshair, and a tooltip with both values.
- **Sales chart (overview):** net sales columns in indigo with returns in red, 4px top radius.
- **Aging bar:** the debts as one 16px stacked bar by age with 2px gaps and round ends, a red ramp darkening with age, the total and overdue amounts above, and a legend that carries every amount. **Row aging:** the same ramp as an 8px by 112px bar in a 44px-tall button with the oldest bucket named in slate beside it; tapping opens a popover listing each bucket's amount.

### DetailsBlock
A native `<details>` that folds a long table under its chart: the summary is indigo 600 text with a chevron that turns on open, a 22px-cornered indigo-mist wash on hover, and the table 12px below.

### Filter Panel
Filters live in a white panel (20px corners, Panel shadow, 16px padding); leading controls (the period segmented control, a white 28.8px-cornered pill of ghost buttons) and trailing meta (range and generated-at in slate 0.75rem, export buttons in the same pill style) sit on the row above it.

## Do's and Don'ts

### Do:
- **Do** put every surface on the lavender ground (#f4f7fe) as a white, borderless panel with 20px corners and the Panel shadow.
- **Do** keep indigo (#422afb) for the one active place, the primary action, links and single-series chart marks; deepen to #3311db on hover.
- **Do** show status as a soft tint with same-hue ink and a word, using the five tones (warn, info, primary, ok, danger).
- **Do** say a warning in words and an icon («يحتاج متابعة» with a warning glyph), never by colour alone.
- **Do** render amounts in tables and lists with the Money component, and use tabular figures in every table.
- **Do** mark every dashboard list `<Table stack>` so it becomes cards below 768px, and keep the first column the row's name.
- **Do** lead a report with KPI tiles and a chart, and fold its long table under a DetailsBlock.
- **Do** mirror physical shadow offsets and chart axes for RTL: shadow x is negative, time runs right to left, the value axis sits on the right.

### Don't:
- **Don't** use negative letter-spacing on any text; it breaks Arabic joining.
- **Don't** lighten the slate ink or the state inks back to Horizon's values; they were darkened for WCAG AA.
- **Don't** let a tenant's colours into the dashboard theme; only its name and logo appear.
- **Don't** add borders to panels or give a table inside a card its own shadow.
- **Don't** reference `var(--font-dm)` in a font stack; name "DM Sans" directly so Arabic falls through to IBM Plex Sans Arabic.
- **Don't** apply this system to the storefront, which keeps its D67 identity (`.store-theme`).
