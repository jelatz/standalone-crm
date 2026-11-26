# Standalone CRM — Project Context & Guardrails

This repository is a pure B2B **Standalone CRM** focused exclusively on **Overview**, **Leads**, **Contacts**, **Companies**, and **Deals** (plus CRM Settings & User administration). Keep legacy workspace modules out (`Projects`, `DealHandoffs`, `Tasks`, `Chat`, `Shared Inbox`).

## Where Rules & SOPs Live

- **Always-on guardrails**: `.agents/rules/*.md` — short, non-negotiable engineering and business invariants automatically loaded into every session.
- **On-demand agent SOP skills**: `.agents/skills/<name>/SKILL.md` — multi-step workflows triggered when relevant.
- **Business process & domain SOPs**: `context/` and `context/sops/` — operational playbooks, qualification rules, pipeline stage policies, and conversion SOPs.
- **UI design system**: `DESIGN.md` — visual tokens, layout rules, and component standards.

## Agent Requirement

Before modifying business logic, status transitions, RBAC/Silo rules, or UI flows for Leads, Contacts, Companies, or Deals, check `context/` and `context/sops/` for any applicable business process docs or SOPs and follow them alongside `.agents/rules/`.
