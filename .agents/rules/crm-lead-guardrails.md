# CRM Lead Guardrails (Always-On)

1. **Leads List Only**: Generated, researched, cleaned, or API-fetched prospects MUST enter **only** `leads` with `status = new`. Never auto-create `companies`, `contacts`, or `deals`, and never invoke `ConvertLeadAction` during lead generation.
2. **SOP & Skill Alignment**: Before modifying lead intake logic or running any lead sourcing workflow, read [lead-intake-and-qualification.md](context/sops/leads/lead-intake-and-qualification.md). For lead generation requests, load and follow `.agents/skills/lead-generator/SKILL.md`.
3. **Mandatory Dry-Run Deduplication & Human Approval**:
   - Always normalize candidates into JSON and run `php artisan crm:leads:import <batch.json> --owner=<email> --dry-run` first.
   - Present the review table (Ready vs. Skipped Duplicates vs. Invalid) and **wait for explicit user approval** before running `--commit`.
4. **Security & Anti-Bot Boundaries**: Keep all database inserts scoped to an active owner authorized under `LeadPolicy::create`; never bypass logins, paywalls, or anti-bot/CAPTCHA checks when researching public leads.
