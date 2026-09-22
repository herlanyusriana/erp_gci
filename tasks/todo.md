# Task List: Monitoring Issue Out to Production APK

## Task 1: Add and configure Reverb/Echo broadcasting foundation ✅

**Description:** Add the approved Laravel Reverb server and browser Echo
foundation, configure environment-driven broadcasting for local development,
and preserve existing application startup behavior. Confirm the exact installed
package APIs before editing configuration.

**Acceptance criteria:**
- [x] Reverb and Echo dependencies/configuration are present and use environment variables for host, port, scheme, and keys.
- [x] `php artisan reverb:start` is available and the frontend can import the chosen Echo client without TypeScript errors.
- [x] No secret, VPS credential, or production value is committed.

**Verification:**
- [x] Tests pass: `php artisan test --compact tests/Feature/MaterialIssueWebTest.php`
- [x] Build succeeds: `npm run build`
- [x] Manual check: start the local Reverb process and confirm it binds to the documented local endpoint.

**Dependencies:** None

**Files likely touched:**
- `composer.json`
- `composer.lock`
- `package.json`
- `package-lock.json`
- `config/broadcasting.php`

**Estimated scope:** Medium: 3-5 files

## Task 2: Establish consistent monitoring permission and launcher discovery ✅

**Description:** Make Monitoring Issue Out visible from Outgoing with clear
wording, and align index/detail/print/channel authorization around the approved
`stock.issue` permission without weakening existing security boundaries.

**Acceptance criteria:**
- [x] The Outgoing module exposes a clearly named Monitoring Issue Out tile linking to the existing named route.
- [x] A user with `stock.issue` can access index, detail, and print; a user without it receives the repository-standard authorization response.

**Verification:**
- [x] Tests pass: `php artisan test --compact tests/Feature/MaterialIssueWebTest.php`
- [x] Build succeeds: `npm run build`
- [x] Manual check: navigate Launcher → Outgoing → Monitoring Issue Out and verify unauthorized access is blocked.

**Dependencies:** Task 1

**Files likely touched:**
- `app/Policies/MaterialIssuePolicy.php`
- `resources/js/Pages/Outgoing/ModuleLauncher.vue`
- `resources/js/i18n/catalogs/outgoing.ts`
- `tests/Feature/MaterialIssueWebTest.php`

**Estimated scope:** Medium: 3-5 files

## Task 3: Implement WIB-aware monitoring query, filters, and summaries

**Description:** Extend the existing Material Issue index query with default
WIB business-day filtering, date/operator/status filters, searchable related
fields, pagination preservation, and aggregate cards grouped by UOM. Keep
detail and print queries intact.

**Acceptance criteria:**
- [x] Default index scope is today in `Asia/Jakarta` using `issue_date`; explicit date ranges override the default.
- [x] Search covers issue number, WO, tag, part, operator, receiver, invoice, and supplier; structured filters cover dates, operator, and status.
- [x] Summary returns issue count, unique WO count, tag count, and qty totals keyed by UOM from the same filter scope.
- [x] Query uses safe grouped conditions, eager loading, PostgreSQL `ilike`, pagination, and `withQueryString()`.

**Verification:**
- [x] Tests pass: `php artisan test --compact tests/Feature/MaterialIssueWebTest.php`
- [x] Build succeeds: `npm run build`
- [ ] Manual check: verify default today, date range, related-field search, multiple UOM cards, and pagination.

**Dependencies:** Task 2

**Files likely touched:**
- `app/Http/Controllers/MaterialIssueController.php`
- `resources/js/types/index.d.ts`
- `resources/js/Pages/Outgoing/MaterialIssue/Index.vue`
- `tests/Feature/MaterialIssueWebTest.php`

**Estimated scope:** Medium: 3-5 files

## Task 4: Publish a post-commit Issue Out event from APK release

**Description:** Define the event payload and dispatch it from the successful
APK release path after the database transaction commits. Preserve API response,
booking, stock, shortage, and idempotency behavior.

**Acceptance criteria:**
- [x] A successful `releaseWithScans` produces one event containing issue identity, WO, operator/receiver, status, item/tag count, and per-UOM totals.
- [x] The event is dispatched only after the transaction commits.
- [x] Releasing with the same idempotency key does not create or broadcast a second business event.
- [x] Event payload does not expose unnecessary sensitive data.

**Verification:**
- [x] Tests pass: `php artisan test --compact tests/Feature/Api/MaterialIssueApiTest.php`
- [x] Build succeeds: `npm run build`
- [ ] Manual check: submit one APK-equivalent release payload and inspect the single event on a local listener.

**Dependencies:** Task 1, Task 3

**Files likely touched:**
- `app/Events/MaterialIssuePosted.php`
- `app/Services/WoService.php`
- `tests/Feature/Api/MaterialIssueApiTest.php`

**Estimated scope:** Medium: 3 files

## Task 5: Add event/channel authorization and backend regression coverage

**Description:** Wire the event to the private monitoring channel, register
channel authorization, and add focused tests for authorized subscription,
unauthorized denial, successful post-commit broadcast, rollback behavior, and
idempotent retry deduplication.

**Acceptance criteria:**
- [x] Event broadcasts on one documented private channel and channel authorization checks `stock.issue`.
- [x] Tests prove authorized users can subscribe and unauthorized users cannot.
- [x] Tests prove successful commit broadcasts and rollback does not broadcast.
- [x] Existing API Issue Out behavior remains unchanged.

**Verification:**
- [x] Tests pass: `php artisan test --compact tests/Feature/Api/MaterialIssueApiTest.php tests/Feature/MaterialIssueWebTest.php`
- [x] Build succeeds: `npm run build`
- [ ] Manual check: use a local authenticated browser session and confirm the channel handshake succeeds only for an authorized user.

**Dependencies:** Task 2, Task 4

**Files likely touched:**
- `app/Events/MaterialIssuePosted.php`
- `routes/channels.php`
- `tests/Feature/Api/MaterialIssueApiTest.php`
- `tests/Feature/MaterialIssueWebTest.php`

**Estimated scope:** Medium: 3-5 files

## Task 6: Build the monitoring dashboard summary, filters, and table

**Description:** Implement the Inertia/Vue dashboard surface using existing
layout, table, badge, pagination, and action patterns. Add summary cards,
filter controls, connection-state placeholder, new-row state, detail/print
actions, and accessible responsive markup.

**Acceptance criteria:**
- [x] Dashboard shows summary cards and per-UOM quantities from server props.
- [x] Date, operator, status, and search controls preserve state and query the named index route.
- [x] Table shows issue, issue date/time, WO, operator, receiver, item/tag count, status, and actions for detail/print only.
- [x] Empty state, loading/refresh state, keyboard focus, table scopes, and theme tokens follow repository conventions.

**Verification:**
- [x] Tests pass: `php artisan test --compact tests/Feature/MaterialIssueWebTest.php`
- [x] Build succeeds: `npm run build`
- [ ] Manual check: exercise filters, pagination, empty state, detail, print, and responsive widths in a browser.

**Dependencies:** Task 3, Task 5

**Files likely touched:**
- `resources/js/Pages/Outgoing/MaterialIssue/Index.vue`
- `resources/js/types/index.d.ts`
- `resources/js/i18n/catalogs/outgoing.ts`
- `tests/Feature/MaterialIssueWebTest.php`

**Estimated scope:** Medium: 3-5 files

## Task 7: Connect Echo live updates, status, reconnect, highlight, and toast

**Description:** Subscribe the dashboard to the authorized private channel and
apply event updates only when compatible with active filters. Add connection
state, automatic reconnect, manual refresh, temporary row highlight, toast,
and client-side deduplication by issue id without polling.

**Acceptance criteria:**
- [x] Connected, reconnecting, and disconnected states are visible and localized.
- [x] A matching event inserts at the top, updates summaries, highlights the row, and shows a silent toast.
- [x] A non-matching event does not enter the filtered list; duplicate issue ids do not duplicate rows or toasts.
- [x] Reconnect and manual refresh work without full-page navigation; no 1–2 second polling loop exists.

**Verification:**
- [x] Tests pass: `php artisan test --compact tests/Feature/MaterialIssueWebTest.php`
- [x] Build succeeds: `npm run build`
- [ ] Manual check: create an Issue Out in a second session and observe live update, then disable/re-enable WebSocket connectivity.

**Dependencies:** Task 6

**Files likely touched:**
- `resources/js/Pages/Outgoing/MaterialIssue/Index.vue`
- `resources/js/types/index.d.ts`
- `resources/js/i18n/catalogs/outgoing.ts`

**Estimated scope:** Medium: 3 files

## Task 8: Add complete i18n/types and launcher/detail/print integration coverage

**Description:** Close the integration gaps around all locale catalogs, shared
TypeScript contracts, discoverability, and read-only detail/print behavior.
Ensure the monitoring event and server props have one stable frontend shape.

**Acceptance criteria:**
- [ ] New user-facing strings exist in balanced id/en/ko catalogs and pass the i18n guard.
- [ ] TypeScript interfaces cover summaries, filters, event payload, and connection state without `any` escapes.
- [ ] Detail and print links remain available while no cancel/update/delete control is rendered.

**Verification:**
- [ ] Tests pass: `php artisan test --compact tests/Feature/MaterialIssueWebTest.php`
- [ ] Build succeeds: `npm run build`
- [ ] Manual check: switch locale, open detail, print a bon, and confirm no mutation action is present.

**Dependencies:** Task 6, Task 7

**Files likely touched:**
- `resources/js/i18n/catalogs/outgoing.ts`
- `resources/js/types/index.d.ts`
- `resources/js/Pages/Outgoing/MaterialIssue/Show.vue`
- `tests/Feature/MaterialIssueWebTest.php`

**Estimated scope:** Small: 1-2 files plus tests

## Task 9: Document local and VPS Reverb operation

**Description:** Write the requested operational runbook for local startup and
VPS deployment, covering process supervision, reverse proxy WebSocket upgrade,
TLS, firewall, environment variables, restart, logs, health checks, and safe
rollbacks. Do not include real secrets or perform deployment.

**Acceptance criteria:**
- [x] Local Reverb/Echo startup and required environment variables are documented.
- [x] VPS instructions cover systemd or equivalent process supervision, reverse proxy WebSocket headers, TLS, firewall, restart, logs, and health check.
- [x] Runbook explicitly warns against committing secrets and identifies which values must be supplied per environment.

**Verification:**
- [x] Tests pass: `php artisan test --compact tests/Feature/MaterialIssueWebTest.php`
- [x] Build succeeds: `npm run build`
- [ ] Manual check: follow the local setup from a clean terminal and validate every VPS command is executable or clearly marked as a placeholder.

**Dependencies:** Task 1, Task 7

**Files likely touched:**
- `docs/websocket-reverb-vps.md`
- `.env.example`

**Estimated scope:** Small: 1-2 files

## Task 10: Run full quality verification and review the implementation

**Description:** Execute the repository quality gates, inspect the final diff,
verify the PRD success criteria, and perform a focused code-quality/security
review before implementation is considered ready.

**Acceptance criteria:**
- [x] Focused tests and full Pest suite pass against the seeded PostgreSQL setup.
- [x] Pint formats all changed PHP files and `npm run build` passes.
- [x] Final review confirms private-channel authorization, no secrets, no mutation actions, no cross-UOM totals, and no unrelated refactor.

**Verification:**
- [ ] Tests pass: `php artisan migrate:fresh --seed --force && vendor/bin/pest`
- [ ] Build succeeds: `npm run build`
- [ ] Manual check: complete the PRD success-criteria checklist and perform the browser real-time flow.

**Dependencies:** Tasks 1-9

**Files likely touched:**
- Changed files from Tasks 1-9 only
- `tasks/plan.md`
- `tasks/todo.md`

**Estimated scope:** Medium: verification-only

## Checkpoint: After Tasks 1-3

- [ ] All foundation tests pass.
- [ ] Application and frontend build cleanly.
- [ ] Query payload, permission boundary, and dependency versions reviewed by a human.

## Checkpoint: After Tasks 4-5

- [ ] API release broadcasts once after commit.
- [ ] Rollback and idempotency tests pass.
- [ ] Private channel denies unauthorized users.

## Checkpoint: After Tasks 6-8

- [ ] Dashboard works end-to-end in a browser.
- [ ] Live event, filtering, deduplication, reconnect, detail, and print are verified.

## Checkpoint: After Tasks 9-10

- [ ] VPS runbook and environment template are safe and complete.
- [ ] Full quality gates and PRD acceptance criteria pass.
- [ ] Ready for code review and later shipping planning.
