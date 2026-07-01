# Working rules — Cashflow project

These are standing instructions from the user. Follow them without exception.

## Communication
- **Never estimate anything.** No time/effort/size/day estimates, no "roughly N",
  no speculative numbers or ranges. If something is unknown, verify it or say it is
  unknown — do not guess.
- **Never present a SQL command without a plain-words explanation first.** Before (or
  alongside) any SQL, explain in simple language exactly what it does and the intention
  behind it. This applies to reads and writes.
- **Diagnostic/fix responses use this exact format, in plain words:**
  ```
  Diagnose:
  Evidence:
  The Fix:
  ```

## Workflow
- Work happens on branch `claude/session-3` in each repo (cashflow-backend,
  cashflow-pk, woo-cashflow). Do not push to other branches without explicit permission.
- On the user's signal **"MRS"**: merge `claude/session-3` into `main` and resync.
- Do every Supabase / production-data task **only with the user's explicit permission**,
  one action at a time. Never mutate prod data without showing a dry-run first.

## Repos
- cashflow-backend — Node/Express API + SQL migrations (update system applies pending
  SQL from /migrations on boot).
- cashflow-pk — React/Vite frontend.
- woo-cashflow — WordPress/WooCommerce PHP plugin.
