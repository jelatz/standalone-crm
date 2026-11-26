# Sourcing Modes Reference

## Mode A — Public Web Research

Use when the user provides an ICP (industry, geography, company size, buyer role) rather than an existing file.

- **Allowed sources**: Public company websites, press releases, public directories, and official team/about/leadership pages via `search_web` or verified public endpoints.
- **Forbidden**: Do not bypass login walls, paywalls, or anti-bot/CAPTCHA gates. If a source is gated or blocked, skip it or report the gap honestly.
- **Mapping**:
  - Set `"source": "outbound"` (unless sourced from a specific event/partner page).
  - Always capture verified `website` (`https://...`) and `company_name` alongside `first_name`, `last_name`, `job_title`, and public work `email` or `phone` when available.
  - Never invent email addresses (`first@domain`) unless explicitly verified from a public source or marked as unverified note for human review.

---

## Mode B — CSV / Spreadsheet / Raw List Cleanup & Enrichment

Use when the user gives a local CSV/TSV/JSON file or pastes messy prospect notes.

- **Cleanup steps**:
  1. Read/parse rows cleanly (handle headers like `Full Name`, `Company`, `Domain`, `LinkedIn`, `Work Email`, `Est Deal Size`).
  2. Split `Full Name` into `first_name` and `last_name` safely; skip header/blank rows.
  3. Normalize `email` (trim + lowercase), `website` (add `https://` if bare domain or keep clean host/URL), and monetary estimates (`"$45,000"` -> `estimated_value_cents: 4500000`, `currency: "USD"`).
  4. Map `source` column values to valid `LeadSourceEnum` (`inbound_web`, `outbound`, `referral`, `event`, `partner`, `other`), defaulting ambiguous values to `"other"` or `"outbound"` as appropriate.
- **Optional web enrichment**: Only enrich missing `website` / `job_title` via public search if the user asks for enrichment.

---

## Mode C — External Lead API (Apollo, Clay, HubSpot, etc.)

Use when the user wants leads fetched from an external provider API or MCP connector.

- **Check integrations first**:
  1. Inspect available MCP servers/skills or configured `.env` API keys (without echoing secrets or reading sensitive tokens into chat).
  2. If the user asks for a supported platform without a connected MCP/plugin, check `search_marketplace` for an official connector and suggest it rather than improvising brittle workarounds.
  3. If an API key is missing in `.env` or environment variables, stop and ask the user to configure it or fall back to Mode A / Mode B.
- **Mapping**:
  - Extract prospect fields into the exact JSON format in [examples/lead-batch.json](../examples/lead-batch.json).
  - Set `"source"` according to channel (`outbound` for Apollo/Clay cold prospecting, `inbound_web` for web form syncs, `partner` for partner portals).
