# DESIGN.md — Internal Enterprise Workspace (CRM, Projects, Chat & Email)

## 1. Aesthetic Direction & Domain Fit
- **Domain & Purpose:** High-density internal operating platform (~1,000 employees across Sales, Account Management, Operations, Project Management, Support, and IT) unifying CRM pipelines, approval-gated project handoffs, real-time chat, and dual-provider email triage.
- **Visual Mood:** High-density utilitarian operational workbench (inspired by Linear, Attio, and Front) — crisp hairline geometry, restrained chrome, and immediate scanability across dense tables, split-pane inboxes, and Kanban boards.
- **Polarity:** Light-first default (`#FAFAFA` canvas, `#FFFFFF` active surfaces) with precision dark mode parity (`#09090B` canvas, `#111114` surfaces) for extended daily operational use.
- **Core Principle:** Establish hierarchy through typography weight, tabular alignment, and `1px` hairline structural dividers rather than stacked drop-shadow cards or decorative color fills.

---

## 2. Color Tokens
Use **1 neutral scale (Cool Zinc)**, **1 primary accent (Deep Cobalt)**, and semantic status tokens **only** where live operational or record state requires them.

| Token | Light (Hex) | Dark (Hex) | Usage Rule |
| :--- | :--- | :--- | :--- |
| `bg-canvas` | `#FAFAFA` | `#09090B` | App shell backdrop and outer workspace canvas |
| `bg-surface` | `#FFFFFF` | `#111114` | Primary split panes, tables, drawers, dialogs, composer trays |
| `bg-subtle` | `#F4F4F5` | `#18181B` | Left navigation rail, hover rows, input wells, badge backgrounds |
| `border-default` | `#E4E4E7` | `#27272A` | Structural `1px` split-pane dividers and control outlines |
| `text-primary` | `#09090B` | `#FAFAFA` | Page titles, entity names, table cell values, primary ink (>= 7:1) |
| `text-secondary` | `#52525B` | `#A1A1AA` | Column headers, timestamps, nav group labels, metadata (>= 4.5:1) |
| `text-muted` | `#71717A` | `#71717A` | Input placeholders, keyboard shortcut hints (`Cmd+K`), disabled labels |
| `accent-primary` | `#1D4ED8` | `#3B82F6` | Primary CTAs, active nav indicator, active tab underline, `:focus-visible` ring |
| `accent-hover` | `#1E40AF` | `#2563EB` | Primary button and link hover state |
| `accent-contrast` | `#FFFFFF` | `#FFFFFF` | Text/icons placed directly on `accent-primary` |
| `status-success` | `#15803D` | `#22C55E` | Deal `won`, project `on_track`, connected WebSocket, verified sync |
| `status-warning` | `#B45309` | `#F59E0B` | `pending_review` handoffs, `at_risk` projects, `snoozed` mail, reconnecting |
| `status-danger` | `#B91C1C` | `#EF4444` | Deal `lost`, `off_track`, `failed` async jobs, validation & auth errors |

---

## 3. Typography Scale & Font Pairing
- **Display / Headings:** `Geist Sans` (`Inter`, `system-ui`, sans-serif fallback), weight `600`, tracking `-0.02em`.
- **Body / UI:** `Geist Sans`, base `14px` (`13px` inside compact table cells and rail items), line-height `1.45`.
- **Monospace (Data / IDs / Shortcuts):** `Geist Mono` (`ui-monospace`, monospace fallback) with `font-variant-numeric: tabular-nums` for currency amounts, project codes (`PRJ-2026-0042`), timestamps, unread badges, and keyboard shortcuts (`Cmd+K`).

| Role | Size / Line-Height | Weight | Usage |
| :--- | :--- | :--- | :--- |
| `page-title` | `18px / 24px` | `600` | Workspace top header / view title |
| `section-title` | `14px / 20px` | `600` | Split-pane panel headers and drawer headings |
| `body` | `13px–14px / 20px` | `400` | Primary list rows, table cells, chat/email body copy |
| `caption` | `12px / 16px` | `500` | Nav labels, timestamps, metadata pills, helper text |

---

## 4. Layout, Grid & Information Density
- **Breakpoints & Shell Geometry:** Verify every screen at `390px` (mobile slide-over nav drawer + single-pane stack) and `1280px+` (desktop persistent `240px` left navigation rail + `44px` compact top bar + full-height main workspace pane).
- **Spacing Scale:** Strict `4px` base grid (`4, 8, 12, 16, 20, 24, 32`).
- **Information Density:** `32px` compact table row height; `28px` navigation rail items; `1px` hairline borders separating rail, top bar, and split panes instead of outer margins around nested boxes.
- **Persistent Surfaces:** The left rail, Reverb connection indicator, live unread badges (`Chat`, `Shared Inbox`, `Handoff Intake`), and bottom-right docked Drafts tray live in `WorkspaceShellLayout.vue` and remain mounted across Inertia page visits.
- **Border Radii:** Controls and inputs `6px`, dialogs/drawers/docked tray `8px`, status pills and user avatars `9999px`.

---

## 5. Component Rules & Required States
- **Buttons:**
  - Primary: solid `accent-primary` with `accent-contrast` ink, clear verb label (`Sign in with Google Workspace`, `Submit handoff`, `Approve & Provision`), zero decorative arrows.
  - Secondary / Ghost: `1px solid border-default` on `bg-surface` or subtle hover fill on `bg-subtle`; async actions lock with `disabled` + inline spinner.
- **Forms & Inputs:**
  - Every input binds `<label for="...">` to `#id`; validation failures show inline `role="alert"` text in `status-danger` with `aria-invalid="true"`.
- **Data Tables & Lists:**
  - Left-align text, right-align monetary values and counts (`tabular-nums`), enforce `truncate` / `min-w-0` on flexible columns so long company or subject names never break layout geometry.
- **Mandatory Non-Happy States:**
  - Every async view, search palette, and live feed specifies **Loading** (skeleton/spinner), **Empty** (plain explanation + next action), **Error** (actionable retry or sign-in error banner), and **Conflict/Offline** (Reverb reconnect banner, optimistic rollback notice).

---

## 6. Copy, Voice & Realistic Data Rules
- **Tone:** Direct, operational, plainspoken enterprise terminology.
- **Copy Constraints:**
  - Use realistic enterprise entities (e.g., `Northwind Logistics`, `Pacific Meridian Health`, `PRJ-2026-0042`, `sales@company.internal`) and uneven string lengths to test truncation.
  - Never invent vanity metrics (`99.9% uptime`, `10,000+ teams`), stock AI persona names (*Sarah Chen*, *Marcus Vance*, *Acme Corp*, *NovaTech*), or marketing fluff (*"Supercharge"*, *"Seamless"*, *"Elevate"*).

---

## 7. Motion & Interaction Budget
- **Transitions:** `150ms` `ease-out` for hover, focus rings, sidebar drawer slide, and `Escape`-dismissible dialogs (`Cmd+K`, docked draft collapse/expand).
- **Prohibited Motion:** Zero scroll-reveal `opacity: 0 -> 1` section animations, zero `animate-ping` status dots (use static `6px` status dots + explicit text like `Live` / `Reconnecting`), and zero `hover:scale-105` on cards. Respect `prefers-reduced-motion`.

---

## 8. Explicit Do's & Don'ts (Anti-Slop Guardrails)
**DO:**
- Use `1px` `border-default` structural dividers, clean typography hierarchy, and alignment first.
- Keep Lucide icons monochrome (`16px`, `stroke-width: 1.75`) inheriting `text-secondary` or `text-primary`.
- Support keyboard shortcuts (`Cmd+K` / `Ctrl+K` to open command palette, `Escape` to close modals/drawers) and visible `:focus-visible` outlines (`2px` `accent-primary`).

**DON'T:**
- No purple/indigo gradients, gradient headline text, or `blur-3xl` floating glows.
- No sparkle (`✨`) icons, raw emojis as navigation chrome, or pastel `40x40` rainbow icon boxes.
- No card-itis (wrapping every metric or list inside nested `rounded-2xl shadow` boxes) or decorative `border-l-4` stripes.
- No Wave-2 editorial starter costume (cream `#FAF9F6` + `Instrument Serif` + italic word + `01 / 02 / 03` eyebrows + middle-dot marketing copy).

---

## 9. Google Stitch Design System Reference
- **Stitch Project ID:** `17521671229474955745`
- **Locked Design System Asset:** `assets/ef1c31b1c96b4ae99131142a2a6ba0c4`
- **Generated Shell Reference Screen:** `projects/17521671229474955745/screens/74207f5bb8e246ae818aefd123b7c228` (*Operational Overview* — 240px persistent left rail with `Cmd+K` trigger, grouped navigation, amber Handoff Intake counter, Chat/Shared Inbox unread badges, static 6px Live status footer, 44px top bar, 4-column hairline KPI strip, 32px row density table, and bottom-right docked `Drafts` tray)
- **Generated Sign In Reference Screen:** `projects/17521671229474955745/screens/b6e69e160b8746ff8102b51da9f7ff00` (*Standalone CRM — Sign In* — centered 400px card, C badge, email/password inputs with Show toggle, remember me, solid `#1D4ED8` Sign In CTA)
- **Generated User Management Reference Screen:** `projects/17521671229474955745/screens/ed10da4e68b8426fb799c8cbb2ca9f63` (*Superadmin User Management* — 32px density user table + Create User modal dialog)
- **Generated CRM Deals Kanban Screen:** `projects/17521671229474955745/screens/16524bf92c294d31af5d007024d0052c` (*CRM Deals Pipeline — Kanban View* — pipeline switcher, Kanban/Table segmented toggle, search & custom field filter bar, hairline-separated stage columns with weighted tabular totals, compact 1px deal cards with rotting warning & stage menu, and overlay Stage-Gate Quick-Fill modal with Director Gate Override toggle)
- **Generated CRM Deal Detail Screen:** `projects/17521671229474955745/screens/c624b7c5799d41d5b8e5e2e0d13f5b46` (*CRM Deal Detail — 3-Pane Workspace* — 280px left Deal properties + typed Custom Fields pane, center Unified Activity Timeline with filter tabs + Pinned Notes banner + embedded TipTap note composer, and 280px right Stakeholders/Collaborators/Attachments pane)
- **Generated Handoff Intake Screen:** `projects/17521671229474955745/screens/e5c8616fcb1448bc81d8feb03769acfc` (*Handoff Intake Queue* — status tabs with counters, 32px row won-deal queue table, sticky 440px review side panel with TipTap scope summary, checklist, template/PM/AM selectors, feedback field, Deep Cobalt 'Approve & Provision' CTA, and secondary Request Changes / Reject controls)
- **Generated Project Workspace Screen:** `projects/17521671229474955745/screens/1fc6e73750b9482a934840cd7be7983b` (*Project Workspace* — PRJ-YYYY-NNNN header with On Track / status pills, co-viewer avatar stack, Board/List/Timeline/Collaborators tabs, 5-column Kanban with WIP limit counts, compact 1px task cards, and slide-over 420px Task Detail Drawer)
- **Generated My Work Screen:** `projects/17521671229474955745/screens/e4ea7ca6cf714bc58f18c44ec98a4f5b` (*My Work Grouped Tasks* — filter strip above Overdue / Today / This Week / Later collapsible groups, 36px task rows with completion checkbox, priority pill, Project code or CRM link pill, watcher badge, and right-aligned tabular due dates)
