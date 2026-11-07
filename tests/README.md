# Free 1.1.0 regression suite

Requires PHP 8.3 CLI, Node.js, the existing disposable WooCommerce testsite and fixture manifest. No HTTP requests. Contract: root FREE-FIX-EXPECTATIONS.md, T-01 through T-26.

From the workspace in PowerShell:

```powershell
& testsite/switch-state.ps1 F
& testsite/run-free-tests.ps1
```

The runner emits one line per T-id and exits 1 if any assertion fails (expected on public 1.0.0). Details are in ignored tests/last-results.json. Every test uses a savepoint within a transaction, rolled back on shutdown; only cewp_char is accepted, InnoDB is mandatory. Tests never call live uninstall: a separate PHP process loads Database/REST classes with option and database recorders. The JS adapter executes the real modal save callback and API function with DOM/fetch stubs; it is not a visual browser test. Dependency fixtures cover the established Agency identifier, active/inactive installed and discovery failure; implementation review must finalize other supported dependency identifiers.

The F switch performs disposable plugin activation/schema preparation before the transaction, following the existing state-switch workflow. It exports the current tracked plugin working tree without tests, metadata or links; audit-f.py checks it against both the working tree and Git HEAD before/after the suite. Original A/B/B-unset/A0 goldens and run-characterization.ps1 are never regenerated. tests/ is export-ignore and must not ship.

Task 21 batch B runs against the API branch export with `& testsite/run-free-tests.ps1 -State FA` (switch with `& testsite/switch-state.ps1 FA` if needed). T-60..77 and T-81 cover lifecycle, REST, tabs, schema and roles. T-78..80 harden `Pricing::filtered()` using the real WordPress dispatcher. See TASK-21-RESULTS.md for the recorded baseline and limitations. Exit 1 is expected while these contracts are unimplemented.

Lifecycle is observed in a fresh offline testsite request with a preinitialized listener before plugin files load and a second listener at plugins_loaded priority 0. This simulates the relevant Pro load-order boundary without activating the legacy Pro engine. Installer probes execute the real Free installer with WordPress hooks, memory-only options and a recording schema boundary; they never run live DDL. The failure fixture returns no schema changes and sets the database error. REST probes use fresh servers and restore the original server. The tab adapter exposes the actual SPA router at its init boundary in a Node VM with a minimal DOM, registers through the public registry, and exercises selection; it is not a browser rendering check. Hook dispatch is asserted before rejection tests so an absent API cannot produce a vacuous green result.
