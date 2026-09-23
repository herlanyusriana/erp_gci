# Implementation Plan: Monitoring Issue Out to Production APK

## Overview

Upgrade the existing Material Issue page into a read-only operational monitor
for Issue Out to Production transactions created by the APK. The work adds
WIB-aware summaries and filters, consistent authorization, a post-commit
broadcast event, private WebSocket delivery through Reverb/Echo, a discoverable
Outgoing launcher tile, and VPS setup documentation. The existing detail and
print flows remain the only transaction actions.

## Architecture Decisions

- Extend `material-issues.index`; do not create a second monitoring data source.
- Use `issue_date` in `Asia/Jakarta` for business-day filters and summaries;
  use `created_at` only for newest-first live ordering.
- Keep quantities grouped by UOM; never calculate a cross-UOM grand total.
- Broadcast a dedicated Issue Out event only after the release transaction has
  committed. Idempotent API retries must not publish duplicate business events.
- Use an authenticated private channel authorized by the same Issue Out
  permission contract as the web page.
- Add Reverb/Echo infrastructure without changing the APK contract.
- Keep the dashboard read-only: detail and print only, no cancel, correction,
  or delete action.
- Keep all user-facing copy in the existing id/en/ko catalogs and use existing
  theme tokens/components.

## Dependency Graph

```text
Broadcasting dependencies and configuration
        |
        +--> Channel authorization and Issue Out permission contract
        |          |
        |          +--> Post-commit Issue Out event
        |                         |
        |                         +--> Echo subscription and reconnect state
        |
        +--> Monitoring query, filters, and summary
                       |
                       +--> Dashboard UI and live row updates
                                      |
                                      +--> Browser/VPS verification
```

## Task List

### Phase 1: Foundation

- [ ] Task 1: Add and configure Reverb/Echo broadcasting foundation
- [ ] Task 2: Establish consistent monitoring permission and launcher discovery
- [ ] Task 3: Implement WIB-aware monitoring query, filters, and summaries

### Checkpoint: Foundation

- [ ] Focused authorization and query tests pass
- [ ] Composer/npm configuration is valid
- [ ] Existing Material Issue detail and print behavior remains unchanged
- [ ] Human reviews the event payload and permission boundary before live UI work

### Phase 2: Real-time backend path

- [ ] Task 4: Publish a post-commit Issue Out event from APK release
- [ ] Task 5: Add event/channel authorization and backend regression coverage

### Checkpoint: Backend real-time path

- [ ] A successful APK release creates exactly one broadcastable event
- [ ] An idempotent retry does not create a duplicate event
- [ ] Unauthorized users cannot subscribe to the private channel
- [ ] Existing API Issue Out tests remain green

### Phase 3: Dashboard experience

- [ ] Task 6: Build the monitoring dashboard summary, filters, and table
- [ ] Task 7: Connect Echo live updates, status, reconnect, highlight, and toast
- [ ] Task 8: Add complete i18n/types and launcher/detail/print integration coverage

### Checkpoint: Product flow

- [ ] A new APK Issue Out appears without a full-page reload
- [ ] Matching active filters update the row and summary; non-matching events do not
- [ ] Detail and print links work and no mutation action is exposed
- [ ] Browser verification covers connected, reconnecting, and disconnected states

### Phase 4: Operations and release verification

- [ ] Task 9: Document local and VPS Reverb operation
- [ ] Task 10: Run full quality verification and review the implementation

### Checkpoint: Complete

- [ ] All PRD success criteria are verified
- [ ] `vendor/bin/pint --dirty --format agent` passes
- [ ] `npm run build` passes
- [ ] Focused and full test suites pass
- [ ] VPS runbook is complete and contains no secrets
- [ ] Ready for `code-review-and-quality`

## Parallelization Opportunities

- Tasks 2 and 3 can run in parallel after Task 1, but Task 3 must define the
  dashboard payload before Task 6 starts.
- Task 9 can be drafted in parallel with Task 8 after the Reverb configuration
  shape is settled, then finalized after Task 10's verification.
- Tasks 4 and 5 are sequential because tests depend on the event contract.
- Tasks 6 and 7 are sequential at the implementation boundary because Task 7
  consumes the UI state and event payload established by Task 6.

## Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Reverb/Echo package APIs differ from assumptions | High | Confirm installed Laravel 12/Reverb versions and official docs before implementation; prove a local handshake early. |
| Existing `MaterialIssuePolicy` permits broader permissions than `stock.issue` | High | Define one explicit monitoring permission contract, test allowed and denied roles, and avoid silently widening access. |
| Summary totals disagree with paginated rows | High | Use a separate filtered aggregate query over the same filter scope and test multiple UOMs. |
| Event fires before transaction commit | High | Use after-commit broadcasting and test rollback/no-event behavior. |
| Live event duplicates on reconnect or idempotent retry | High | Use issue id as client deduplication key and preserve server idempotency behavior. |
| Browser remains stale after WebSocket disconnect | Medium | Show connection state, reconnect automatically, and retain a manual refresh action. |
| VPS reverse proxy or TLS misconfiguration | High | Include a concrete systemd/process, proxy, firewall, environment, and health-check runbook; verify with a real browser. |
| Existing frontend i18n catalog becomes unbalanced | Medium | Update all three locale catalogs and run the repository i18n check through `npm run build`. |

## Open Questions

None. Product decisions are approved in `docs/prd/monitoring-issue-out.md`.
