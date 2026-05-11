# TODO

`0.1.0-alpha` shipped 2026-05-11. No feature in flight. The honest "what's next" is **stop building, start using** — real-world integration will surface things test-writing won't. This file is the operational landscape for the post-alpha period.

When a real next feature emerges, it goes under `## Active` with the usual Steps / Files / Done criteria block, mirroring entries in `tasks/done.md`.

---

## Active

_(no feature in flight — `0.1.0-alpha` shipped 2026-05-11)_

---

## Post-0.1.0-alpha — operational landscape

The package is at a stable resting point. The items below are **operational guidance**, not features in flight. None of them should become a TDD-loop feature commit; they're external actions, dogfooding, or optional polish.

### 1. Verify the first CI run

The matrix CI workflow (F56, `.github/workflows/tests.yml`) ran on the push that landed `0.1.0-alpha`. Check the result before doing anything else:

```bash
gh run list --limit 5
# or open https://github.com/musaajallo/gmb-pay/actions
```

If the matrix is green across all six cells (PHP 8.3/8.4 × Laravel 11/12/13), the alpha is good. If a cell fails, expect one of:

- **Laravel/Testbench resolution conflict** — the `composer require ... --no-update && composer update` step in the workflow may need adjusted version constraints
- **PHP 8.4 quirk** — Larastan or a transitive dep may not fully support 8.4 yet; bump or pin
- **A timezone / `Carbon` second-rounding edge case** — the F26+ time-sensitive tests use a 5-second tolerance, but CI runners are sometimes slower

Both classes of failure are <30min fixes.

### 2. Register on Packagist

Until this happens, anyone who `composer require africs/gmb-pay`s the package — including future-you — must add the GitHub repo manually to their app's `composer.json` under `repositories`. Packagist registration is a one-time external action:

1. Visit https://packagist.org and sign in with GitHub
2. Click **Submit** and paste `https://github.com/musaajallo/gmb-pay`
3. Enable the GitHub webhook for auto-updates on every push (Packagist will offer this on the package page)

After registration, the README's install command (`composer require "africs/gmb-pay:^0.1.0-alpha@dev"`) just works against the public Packagist mirror.

### 3. Dogfood it in a real app

This is what `0.1.0-alpha` exists for. Drop the package into your next app, wire one charge flow end-to-end against Modempay's **test** environment, hit edge cases. Real usage surfaces things no test catches:

- Ergonomic friction (param ordering, default opts, naming)
- Missing affordances (helpers you reach for that don't exist)
- Error messages that read fine in isolation but are useless mid-flow

Each surfaced issue → a `0.1.x` patch (bugfixes / docs) or `0.2.0` if the public API shape changes. The TDD loop from `CLAUDE.md` still applies.

### 4. Chase external blockers (in parallel)

These don't block today's package but unlock the next chunk of functionality:

- **Modempay refunds** — email Modempay support asking whether a public refund endpoint is on their roadmap. Their `transactions` resource has a `refunded` status (`https://docs.modempay.com/documentation/core/transactions`), implying refunds exist internally; the question is whether they'll be exposed
- **Wave Gambia merchant access** — apply via Wave's merchant onboarding. The docs at `https://docs.wave.com` cover the Senegal API; Gambia is a separate flow. Unblocks F46–F47
- **Waychit merchant access** — apply via `https://waychit.com/developers`. No public docs yet; merchant onboarding may surface them. Unblocks F48–F49
- **Phase 2 gateways** (Gamswitch, QMoney, Africell Money) — only pursue when there's a real use case in an app you're building

### 5. Optional polish (only if it pays off)

- **Composer scripts** in `composer.json` so you don't type `vendor/bin/...`:

  ```json
  "scripts": {
      "test": "vendor/bin/pest",
      "lint": "vendor/bin/pint --test",
      "fix": "vendor/bin/pint",
      "analyse": "vendor/bin/phpstan analyse --memory-limit=512M"
  }
  ```

  Then `composer test` / `composer lint` / `composer analyse`. Five minutes; small comfort.

- **Codecov badge** — add `--coverage` to the Pest CI step and upload to codecov.io. Adds ~30s per matrix cell; only worth it if you visually scan coverage often
- **Stable `0.1.0` tag** — after a week or two of real-world use without surprises, tag `0.1.0` and drop the `@dev` requirement from the README install command
- **Auto-merge dependabot PRs for patch updates** — `.github/dependabot.yml` + auto-merge workflow. Only useful once the package has more than the maintainer touching it

### When NOT to act on this list

- Don't add features speculatively. The alpha exists to discover what's *actually* needed
- Don't pursue Phase H (views) or Phase J stubs — they were explicitly deferred (`README.md` "Scope notes"); reviving them needs a real surfaced need, not nostalgia
- Don't tighten Larastan past level 6 or add stricter Pint rules unless a real bug slips through current checks
- Don't write a `CONTRIBUTING.md` (F60) until someone outside the maintainer actually wants to contribute

---

## Blocked

### F16 — ModempayDriver::refund()

**Blocked by:** Modempay's public docs (`https://docs.modempay.com`) document a `refunded` transaction status but **no API endpoint to create a refund**. `/documentation/core/transactions` only exposes `GET /v1/transactions/{id}` and `GET /v1/transactions`. The payment-intents management page only documents `PATCH /v1/payments/<id>` to **cancel** an intent (pre-capture), not to refund a completed one.

**Unblock conditions:**
- Modempay publishes a refund endpoint, OR
- Merchant onboarding surfaces an undocumented endpoint we can verify against, OR
- We accept refunds-via-dashboard-only and ship `ModempayDriver::refund()` as a `BadMethodCallException` override with a Modempay-specific message (degraded scope; would still leave the F16 box unchecked since the feature spec was a real API call)

**When unblocked:** drop in here, write a `tests/Drivers/Modempay/ModempayDriverRefundTest.php` mirroring the F14/F15 shape, override `ModempayDriver::refund()`, tick F16, commit `F16: ModempayDriver::refund() real implementation`.
