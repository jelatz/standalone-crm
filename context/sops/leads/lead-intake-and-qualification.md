# Standard Operating Procedure: Lead Intake, Deduplication & Qualification

## 1. Purpose & Golden Rule

This SOP governs how unqualified B2B prospects enter and progress through **Standalone CRM** (`Overview`, `Leads`, `Contacts`, `Companies`, `Deals`).

> **Non-Negotiable Invariant:** Every generated or imported prospect enters **ONLY** the `leads` list with `status = new`. Lead generation must **never** auto-create **Deals**, **Companies**, or **Contacts**, and must **never** run Convert Lead without explicit human qualification and approval.

---

## 2. Editable Ideal Customer Profile (ICP) Template

Customize these criteria before running large outbound or web research batches:

- **Target Industries**: `B2B SaaS`, `Logistics & Supply Chain`, `Manufacturing`, `Professional Services` *(edit to match your offer)*
- **Company Size**: `20–500 employees` *(or target revenue band)*
- **Target Geographies**: `North America`, `Europe`, `APAC` *(customize per territory)*
- **Buyer Personas / Job Titles**: `VP Operations`, `Head of IT`, `COO`, `Director of Procurement`, `RevOps Lead`
- **Explicit Exclusions**: Competitors, existing active clients (`companies.lifecycle_stage = active_client`), students/personal Gmail/Yahoo addresses when corporate domains are available, B2C consumers.

---

## 3. Minimum Intake Data Gate

A candidate row is **not eligible** for insertion into `leads` unless it satisfies:

1. **Person Identity**: `first_name` (non-empty) and `last_name` (non-empty)
2. **Organization**: `company_name` (required for B2B prospect tracking; fill company or organization name)
3. **Reachable Channel** *(at least one)*: valid corporate `email`, `phone`, or `website`
4. **Source Tag** (`LeadSourceEnum`): one of `inbound_web`, `outbound`, `referral`, `event`, `partner`, `other`
5. **Initial Status**: Strictly `new` (`LeadStatusEnum::New`)

Optional helpful fields: `job_title`, `estimated_value_cents` (integer cents), `currency` (3-letter ISO code, default `USD`), and active `custom_fields` defined for `entity_type = lead`.

---

## 4. Three Approved Sourcing Channels

| Mode | Source Input | Default `source` Value | Required Normalization |
| :--- | :--- | :--- | :--- |
| **A. Public Web Research** | ICP prompt + public company/leadership pages | `outbound` | Extract verified business title, company name, domain/website, and public work email/phone; never bypass logins, paywalls, or anti-bot/CAPTCHA gates. |
| **B. CSV / Raw List Cleanup** | Exported spreadsheet, event attendee list, or notes | Map column or use `event` / `partner` / `outbound` / `other` | Split full names into `first_name` / `last_name`, lowercase emails, normalize currency to `estimated_value_cents`, and drop junk/blank rows. |
| **C. External Lead API** | Apollo, Clay, HubSpot, or configured MCP/REST API | `outbound` / `partner` / `inbound_web` | Use only user-configured credentials/connectors; normalize API payload fields into `UpsertLeadData` JSON schema. |

---

## 5. Deduplication Policy

Before proposing rows for approval, candidates must pass Silo-scoped duplicate checks (`php artisan crm:leads:import <batch.json> --owner=<email> --dry-run`) against:

1. **Intra-batch duplicates**: Same lowercase `email` or same `(first_name, last_name, company_name)` within the upload.
2. **Existing Leads (`leads.email`)**: Case-insensitive match among leads visible to the owner.
3. **Existing Contacts (`contact_emails.email`)**: Case-insensitive match among contacts visible to the owner.
4. **Existing Lead Person + Company**: Normalized `(first_name, last_name, company_name)` already present in visible `leads`.
5. **Existing Company Domain (`companies.domain`)**: Normalized host from `website` already matches a visible company's `domain` (flag for review rather than creating duplicate account prospecting).

---

## 6. Mandatory Approval Gate

1. Never insert generated leads automatically.
2. Always run `--dry-run` first and present a clear review table listing:
   - **Ready to Import (New)**
   - **Skipped Duplicates** (with match reason)
   - **Invalid / Incomplete Rows** (with validation reason)
3. Wait for explicit human approval (`approve all`, `approve specific rows`, `edit fields`, or `cancel`).
4. Only after approval, run `--commit` so clean rows enter `/app/crm/leads` with `status = new`.

---

## 7. Qualification Lifecycle & Conversion Readiness

Leads progress inside `/app/crm/leads` through:

1. `new` — Sourced or captured; not yet contacted.
2. `contacted` — Initial outreach sent or logged via Notes.
3. `working` — Active discovery / two-way qualification conversation in progress.
4. `qualified` — Confirmed ICP fit, real problem/need, reachable stakeholder, and scope suitable for a Deal.
5. `unqualified` — Disqualified (`disqualified_reason` required — e.g., `No budget`, `Outside ICP`, `Non-responsive`).

### Convert Lead Readiness Checklist (`qualified` → `converted`)
Only run **Convert Lead** manually on `/app/crm/leads/{id}` once:
- Status is `qualified` (or confirmed opportunity ready for pipeline tracking).
- Target Company choice is verified (**Create new company** vs **Link existing company**).
- Initial Deal fields (`deal_title`, `pipeline`, `stage`, `amount`, optional `win_probability` override) are confirmed if opening a Deal.
