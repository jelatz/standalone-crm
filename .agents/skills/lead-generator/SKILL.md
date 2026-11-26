---
name: lead-generator
description: >-
  Use when the user asks to generate, research, clean, enrich, or import unqualified
  B2B leads into Standalone CRM from web research, CSV/lists, or external lead APIs,
  enforcing deduplication, human approval, and Leads-only insertion (status=new).
---

# Lead Generator Skill

Generates, cleans, deduplicates, and stages unqualified B2B prospects into `/app/crm/leads` with `status = new`. **Never** auto-creates Companies, Contacts, or Deals.

## Non-Negotiable Rules

1. Read [context/sops/leads/lead-intake-and-qualification.md](../../../context/sops/leads/lead-intake-and-qualification.md) first to verify current ICP rules and minimum intake fields.
2. Prospects enter **`leads` only** (`status = new`). Do **not** create Companies, Contacts, or Deals, and do **not** run `ConvertLeadAction`.
3. Never insert rows without running deterministic deduplication (`--dry-run`) and receiving **explicit user approval** on the staged batch.

## Step-by-Step Workflow

### Step 1 — Confirm Mode, Input & Owner
Verify from the user request (ask one concise question if missing):
- **Mode** (see [references/sourcing-modes.md](./references/sourcing-modes.md)):
  - **Mode A**: Public Web Research (ICP criteria / industry / titles / region)
  - **Mode B**: CSV / Spreadsheet / Raw List Cleanup & Enrichment (file path or pasted rows)
  - **Mode C**: External Lead API / MCP Connector (Apollo, Clay, HubSpot, etc.)
- **CRM Owner Email**: Active user who will own the imported leads (e.g., `superadmin@example.com` or assigned sales rep email).

### Step 2 — Gather & Normalize Candidates
Follow [references/sourcing-modes.md](./references/sourcing-modes.md) for the active mode:
- Normalize every prospect to match [examples/lead-batch.json](./examples/lead-batch.json):
  - `first_name` (required), `last_name` (required)
  - `company_name` (required by B2B SOP), `job_title`
  - At least one contact channel: `email` (lowercase), `phone`, or `website`
  - `source`: one of `inbound_web`, `outbound`, `referral`, `event`, `partner`, `other`
  - `estimated_value_cents` (integer cents, optional), `currency` (3-letter code, default `USD`)
  - Omit `status` (or set `"new"`; CLI rejects pre-converted leads)

### Step 3 — Dry-Run Deduplication & Validation
Write the normalized JSON array to the conversation scratch folder (e.g., `<Artifact Directory>/scratch/lead-batch.json`) and run:

```bash
php artisan crm:leads:import <path-to-batch.json> --owner=<owner-email> --dry-run --json
```

This checks authorization (`LeadPolicy::create`), validates every row against CRM schema rules, and flags intra-batch duplicates plus matches in visible `leads`, `contact_emails`, and `companies.domain` without touching the database.

### Step 4 — Review Table & Mandatory Approval Gate
Present a clean Markdown table to the user grouped clearly by outcome:
- **Ready to Import (`ready`)**: `#`, `Name`, `Title`, `Company`, `Email / Website`, `Source`, `Est. Value`
- **Skipped Duplicates (`duplicate`)**: `#`, `Name`, `Company`, `Match Reason`
- **Needs Fix / Invalid (`invalid`)**: `#`, `Name`, `Validation Reason`

Ask explicitly for confirmation before importing (e.g., **"Reply `approve` to add these N leads to `/app/crm/leads` (as `new`), specify row numbers to keep, or tell me what to adjust."**). **STOP and wait.**

### Step 5 — Approved Commit
Once the user approves (and if they kept a subset, write only the approved rows into a filtered JSON file), run:

```bash
php artisan crm:leads:import <path-to-approved.json> --owner=<owner-email> --commit --json
```

Verify `"inserted_count"` matches expectations, confirm zero Companies/Contacts/Deals were created, and share the inserted Lead IDs plus a direct link to `/app/crm/leads`.
