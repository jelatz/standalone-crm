# Phase 2 Projects & Deal Handoffs Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` together with `design-md` and `core-implementation` to implement Phase 2 task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement the Phase 2 Projects & Approval-Gated Deal Handoffs module (`Templates`, `Handoffs`, `Projects`, `Tasks`) enforcing strict siloed visibility (`HasSiloVisibility`), deterministic handoff transitions, idempotent `TrackedJob` project provisioning, optimistic Task Kanban concurrency (`version` + WIP limits + Reverb presence), and Stitch-locked VILT UI.

**Spec:** [phase_2_plan.md](file:///Users/jelatz/.gemini/antigravity/brain/37892cd6-590c-415f-adf1-f30e9b3514d4/phase_2_plan.md) & [2026-11-25-internal-workspace-architecture-plan.md](./2026-11-25-internal-workspace-architecture-plan.md)

## Global Constraints
- PostgreSQL 17 (`project_code_seq` sequence, partial unique active-handoff index, `UNIQUE(projects.deal_handoff_id)`, UUIDv7 PKs via `HasUuidV7`).
- Strict `Controller -> FormRequest/Data DTO -> Action -> Model` + Laravel `Policies` (explicit `Gate::authorize` on every route for `RouteAuthorizationAuditTest`).
- Strict Siloed Scoping (`HasSiloVisibility::scopeVisibleTo`) & `Superadmin`/`Admin` parity across all policies, queries, timelines, and broadcasts.
- Optimistic locking (`version`) on Tasks (`409 Conflict` via `OptimisticLockConflictException`) and WIP limit enforcement (`422`) in `MoveTaskAction`.
- All `DB::afterCommit` job dispatch & broadcast tests live in `Tests/Integration/` using `DatabaseTruncation`.

## Tasks
- [ ] **Task 2.1:** Projects 8-table schema migration (`project_templates`, `project_template_tasks`, `deal_handoffs`, `projects`, `deal_project`, `project_stages`, `tasks`, `task_watchers`), `project_code_seq`, Enums (`#[TypeScript]`), Models, Policies, `ModularDomainServiceProvider` wiring, `ProjectDefaultsSeeder`, and Feature policy/silo tests.
- [ ] **Task 2.2:** Deal Handoff state machine (`DealHandoffStateMachine`), submission/resubmit/changes-requested/reject/approve Actions & `DealHandoffController`, `ProvisionProjectFromHandoffJob` (`TrackedJob`) + idempotent `ProvisionProjectFromHandoffAction`, `HandoffStatusChanged`, `unreadCounts.handoffs` shared prop, Feature & `DatabaseTruncation` Integration tests.
- [ ] **Task 2.3:** Projects CRUD, stages management, Tasks CRUD + watchers + 1-level subtasks, `MoveTaskAction` (`version` lock, WIP limit, `done` -> `completed_at`, fractional rebalance), `ListMyWorkAction`, `QueryEntityTimelineAction` project/task/handoff extension, `TaskBoardChanged` + `project-board.{projectId}` presence channel auth, Feature & Integration tests.
- [ ] **Task 2.4:** Google Stitch screen generation (`Handoff Intake`, `Project Workspace`, `My Work`), `DESIGN.md` §9 update, Vue 3 UI (`HandoffIntakePage`, `HandoffDetailPage`, `ProjectsIndexPage`, `ProjectWorkspacePage`, `MyWorkPage`, drawers/components), CRM `DealKanbanBoard`/`DealDetailPage` handoff hook, `WorkspaceShellLayout` `"My Work"` + live `handoffs` badge wiring, and Vitest suite.
- [ ] **Task 2.5:** Full verification (`pest`, `typescript:transform`, `typecheck`, `vitest`, `build`, `route:cache`, `pint`, local `migrate:fresh --seed`) + whole-phase `code-auditor` security & anti-slop review.
