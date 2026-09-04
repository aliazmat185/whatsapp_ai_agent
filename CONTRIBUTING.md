# Contributing

Thanks for considering contributing to the WhatsApp AI Sales Agent platform. This document covers how to get set up, the workflow for submitting changes, and what's expected of a pull request.

## Getting set up

Follow the [Installation](README.md#installation) and [Configuring the .env file](README.md#configuring-the-env-file) sections of the README first. You do not need real WhatsApp/Meta or AI provider credentials to develop or run the test suite — only to exercise live messaging or a live AI provider.

## Reporting bugs

Open an issue with:

- What you did, what you expected, and what actually happened
- Steps to reproduce (a failing test is even better — see below)
- Relevant logs/stack traces, with any secrets redacted
- Your PHP version and OS, if the bug looks environment-related

## Proposing features

Open an issue describing the problem you're trying to solve before writing code, especially for anything touching the database schema, the AI agent's tool set, or vendor/tenant isolation — those are covered by explicit design decisions in [`PLAN.md`](PLAN.md), and a change there should be discussed against that context rather than revisited silently in a PR.

## Branch & PR workflow

1. Fork the repo and branch off `main` (or `develop`, if that's what the maintainers are targeting for the next release) — use a short descriptive branch name, e.g. `fix/checkout-stock-race` or `feat/easypaisa-live-integration`.
2. Make your change, with tests (see below).
3. Run the full check locally before opening a PR:
   ```bash
   vendor/bin/pint          # auto-fix code style
   php artisan test         # full suite must pass
   ```
4. Open a pull request against the same branch you branched from. Describe *why* the change is needed, not just what changed, and link the issue it addresses if there is one.
5. CI (`.github/workflows/ci.yml`) runs the test suite against MySQL/Redis and a separate Pint check on every PR — both must be green before a maintainer will review it.

## Coding standards

- **Style**: [Laravel Pint](https://laravel.com/docs/pint), run via `vendor/bin/pint`. Don't hand-format around what Pint would otherwise fix — run it and commit the result.
- **Multi-tenancy is not optional**: any new vendor-owned table or query must be scoped by `vendor_id` — use the existing `BelongsToVendor` trait / policy pattern (`app/Traits/BelongsToVendor.php`, `app/Policies/`) rather than inventing a new isolation mechanism. A PR that lets one vendor read or write another vendor's data will not be merged regardless of what else it does.
- **The AI agent doesn't touch the database directly.** New agent capabilities are added as tools in `app/Services/Ai/ToolRegistry.php` that delegate to a real service (`CartService`, `ProductSearchService`, etc.); the agent should never be trusted to compute a price, change stock, or mark something paid on its own.
- **Secrets never get logged.** If your change adds a new field that can carry a token/secret through `webhook_logs` or application logs, mask it the way `app/Support/LogMasker.php` already masks the existing ones.
- Keep PRs focused — a refactor and a behavior change in the same PR are harder to review and to revert independently.

## Tests

- New behavior needs a test. Bug fixes should include a test that fails before the fix and passes after it.
- Unit tests (`tests/Unit`) for pure logic (state machine transitions, price/cart math, distance calculations, policy checks) — no HTTP, no real DB round-trips beyond what's unavoidable.
- Feature tests (`tests/Feature`) for anything going through a controller, a Livewire component, a queued job, or the webhook endpoint.
- The suite runs against an in-memory SQLite database with the AI/WhatsApp environment variables fixed to dummy test values (see `phpunit.xml`) — never point a test at a real Anthropic/Meta/Qdrant endpoint; fake the HTTP client instead (see existing tests under `tests/Feature/Ai` and `tests/Feature/Webhook` for the established pattern).
- Run `php artisan test` before pushing. `php artisan test --filter=SomeTestName` or `php artisan test path/to/File.php` to iterate on one area.

## Commit messages

Write commit messages that explain *why*, not just *what* — "Fix duplicate order creation on retried webhook" is more useful than "Fix bug". Squash fixup/WIP commits before opening the PR for review where practical.

## Code of Conduct

Be respectful and assume good faith. Disagreements about approach are fine and expected; personal attacks, harassment, or dismissiveness toward other contributors are not, and issues/PRs that devolve into that will be closed.
