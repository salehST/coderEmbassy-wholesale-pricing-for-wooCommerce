# Task 21 tests-only baseline

Base: `873702962f24122cc481e742e121be8463a14353` (`release/1.1.0-api`).

Verification: `& testsite/run-free-tests.ps1 -State FA` -> exit 1 (expected red baseline).
Both export audits: `17 verified`. Five protected golden files and the characterization runner unchanged.
Existing T-01..26 and T-30..58: 55/55 green. New batch B: 19 red. Hardening: 1 green, 2 red.

| Row | Result | Assertions | First failing assertion / evidence |
|---|---|---:|---|
| T-60 | red | 5 | loaded once with early Pro-style listener expected 1 got 0 |
| T-61 | red | 5 | successful upgrade notifies after version saved exactly once expected [["1.0.0","1.1.0","1.1.0"]] got [] |
| T-62 | red | 7 | failure remains retryable expected "1.0.0" got "1.1.0" |
| T-63 | red | 6 | listener route registered expected 200 got 404 |
| T-64 | red | 4 | overwrite attempt actually dispatched expected 1 got 0 |
| T-65 | red | 3 | tabs filter dispatched expected 1 got 0 |
| T-66 | red | 6 | duplicate extension rejected expected 1 got 0 |
| T-67 | red | 4 | authorized bootstrap establishes registry expected 1 got 0 |
| T-68 | red | 8 | JS registry available |
| T-69 | red | 3 | extension default merged expected false got null |
| T-70 | red | 5 | extension typed round trip expected true got null |
| T-71 | red | 3 | unknown write rejected expected 400 got 200 |
| T-72 | red | 3 | invalid core type rejected expected 400 got 200 |
| T-73 | red | 4 | extension eligibility applied expected true got false |
| T-74 | red | 4 | existing dealer role accepted expected "vip" got "wholesale" |
| T-75 | red | 4 | null explicitly clears selected role expected null got "wholesale" |
| T-76 | red | 5 | membership creates new context expected 2 got 0 |
| T-77 | red | 3 | cached before role write expected 1 got 0 |
| T-78 | green | 5 | All assertions passed |
| T-79 | red | 5 | removal persists after dispatch expected false got 20 |
| T-80 | red | 5 | Thrown listener leaves cewp_test_nested on wp_current_filter; original callback restored and next dispatch works. |
| T-81 | red | 4 | later valid eligibility applied expected true got false |

The all-green old rows contain 3,782 assertions; all new rows contain 101 assertions.

T-62 combines an already-current (green) installer probe with failed-upgrade version retention (red). T-64 and T-67 require successful hook dispatch before testing protections, so absent APIs do not pass accidentally. T-76 confirms eligibility changes after a membership write, but hook counts are zero because the new role hooks are absent. T-81 checks malformed eligibility cannot poison the next listener.

Hardening details: T-78 nested same-tag calls return 2 for the outer input 1 and 101 for inner input 100, and a later dispatch returns 4. T-79 skips the removed listener during each call, but restores it afterwards (has_filter returns priority 20). T-80 propagates the original exception and restores callback identity, but leaks the dispatch stack. The test restores its stack snapshot afterwards; earlier CSV redirect probes already leave wp_redirect entries, so it compares against the snapshot rather than assuming an empty stack.

No plugin source changes. No live schema upgrade, legacy Pro boot, browser visual check, push or deployment. Full machine-readable assertion details remain in ignored `tests/last-results.json`; runnable fixtures and this record are committed.
