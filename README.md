# WhatsApp AI Sales Agent

A multi-tenant, multi-vendor commerce platform where each vendor's customers order entirely through **WhatsApp**, served by an AI conversation agent (Claude, with pluggable OpenRouter/Gemini/Hugging Face backends) instead of a chat widget or an app.

A customer messages a vendor's WhatsApp number, the agent figures out which store to route them to, answers product questions (optionally backed by a RAG knowledge base), builds a cart, walks them through checkout, and hands a structured order to the vendor's dashboard — all without the vendor or the customer touching a form.

## Contents

- [How it works](#how-it-works)
- [Tech stack](#tech-stack)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuring the .env file](#configuring-the-env-file)
- [Running the app locally](#running-the-app-locally)
- [Demo data & login credentials](#demo-data--login-credentials)
- [Running the tests](#running-the-tests)
- [Code style](#code-style)
- [Artisan commands](#artisan-commands)
- [Project structure](#project-structure)
- [Contributing](#contributing)
- [License](#license)

## How it works

```
Customer on WhatsApp
        │  message
        ▼
Meta WhatsApp Cloud API ──▶ POST /webhook/whatsapp (signature-verified)
        │
        ▼
ProcessInboundWhatsAppMessage job  →  resolves vendor + conversation, stores message
        │
        ▼
RunConversationAgent job  →  ConversationStateMachine + ClaudeAgentService
        │                         (tool-calling: search products, manage cart,
        │                          locate nearest store, start checkout, escalate)
        ▼
Reply sent back to the customer on WhatsApp, order/cart state persisted to MySQL
```

Key pieces:

- **One WhatsApp number per vendor**, registered under a single platform-owned WhatsApp Business Account (WABA) — vendors never touch Meta's dashboard themselves.
- **Row-scoped multi-tenancy**: every vendor-owned table carries a `vendor_id`; policies and the `BelongsToVendor` trait enforce it everywhere so one vendor can never see another's data.
- **The AI agent never talks to the database directly.** It calls a fixed set of tools (`search_products`, `get_store_candidates`, `add_to_cart`, `start_checkout`, `escalate_to_human`, …); real services own price/stock truth and every write.
- **Idempotent webhooks**: Meta's at-least-once delivery is absorbed by a unique `wa_message_id` constraint, so retried webhook deliveries never create duplicate messages or orders.
- **Optional RAG knowledge base**: vendors can upload documents; they're chunked, embedded (OpenAI or Gemini embeddings) and stored in Qdrant for the agent to retrieve from during a conversation.

The full architecture, database schema, API surface, and design decisions are written up in detail in [`PLAN.md`](PLAN.md) — read that first if you're changing anything structural.

## Tech stack

| Layer | Choice |
|---|---|
| Backend | Laravel 13 (PHP 8.3+) |
| Admin/vendor panels | Livewire (Blade, server-rendered, no separate SPA) |
| Database | MySQL 8 (spatial functions used for nearest-store lookups) |
| Queue / cache | Redis |
| AI agent | Claude (Anthropic) by default; OpenRouter, Gemini, and Hugging Face are supported alternate providers |
| Embeddings / RAG | OpenAI or Gemini embeddings, stored in Qdrant |
| Messaging | Meta WhatsApp Cloud API |
| AuthZ | Laravel Sanctum + `spatie/laravel-permission` (roles: `super_admin`, `admin_staff`, `vendor_owner`, `vendor_staff`) |

## Requirements

- PHP 8.3 or newer, with the `mbstring`, `dom`, `fileinfo`, `pdo_mysql`, and `redis` extensions
- Composer 2.x
- Node.js 20+ and npm (for the Vite-built admin/vendor panel assets)
- MySQL 8.0+ (needed for spatial store-distance queries)
- Redis 7+ (queue driver and cache)
- Optional, only if you're working on the RAG knowledge base: [Qdrant](https://qdrant.tech) — a ready-to-run service is in `docker-compose.yml`

You do **not** need real WhatsApp/Meta or Anthropic credentials to install the app, run migrations, or run the test suite — those are only required to actually send/receive WhatsApp messages or call a live AI provider.

## Installation

```bash
git clone https://github.com/aliazmat185/whatsapp_ai_agent.git
cd whatsapp_ai_agent

composer install
cp .env.example .env
php artisan key:generate

npm install
npm run build
```

Then configure your database connection in `.env` (see the next section), and:

```bash
php artisan migrate
php artisan db:seed        # optional — creates demo roles, packages, a vendor and login users
```

If you'd rather not install MySQL/Redis/Qdrant yourself, start them with Docker (see [Running the app locally](#running-the-app-locally)).

### One-shot setup

`composer.json` also defines a `setup` script that does most of the above for you (install dependencies, copy `.env`, generate the app key, migrate, and build frontend assets):

```bash
composer run setup
```

You still need a running MySQL/Redis to point `.env` at before running it.

## Configuring the .env file

`.env.example` is grouped by concern and every variable has a comment explaining what it does. The short version:

**Always required for the app to boot:**

| Variable | Notes |
|---|---|
| `APP_KEY` | Generated for you by `php artisan key:generate` — never set this by hand. |
| `DB_*` | MySQL connection. Defaults assume `127.0.0.1:3306`, database `ai_sales_agent`, user `root`, no password — matches the Docker Compose service below. |
| `QUEUE_CONNECTION=redis`, `REDIS_HOST` etc. | AI replies and WhatsApp sends are dispatched to the queue, not run inline — the queue worker must be running for the agent to actually respond. |

**Only required if you're integrating real WhatsApp messaging:**

| Variable | Notes |
|---|---|
| `WHATSAPP_WABA_ID`, `WHATSAPP_SYSTEM_USER_TOKEN`, `WHATSAPP_APP_SECRET` | Platform-level Meta credentials — one set for the whole platform, shared across every vendor (see `PLAN.md` decision-8 / A11). |
| `WHATSAPP_WEBHOOK_VERIFY_TOKEN` | Arbitrary string you choose; Meta echoes it back during webhook verification. |
| `WHATSAPP_DEV_PHONE_NUMBER_ID` | Seeds a dev vendor's number directly, skipping the OTP onboarding wizard, for local testing. |
| `PUBLIC_ASSET_BASE_URL` | Meta fetches product images over the public internet — point this at an ngrok/Cloudflare Tunnel URL when `APP_URL` is `localhost`. |
| `WHATSAPP_FLOW_ID`, `WHATSAPP_FLOW_PRIVATE_KEY_PASSPHRASE` | Only needed if you use the multi-select product Flow — set after running the `whatsapp:flow:*` artisan commands below. |

**Only required if you're calling a live AI provider:**

| Variable | Notes |
|---|---|
| `AI_PROVIDER` | `anthropic` (default), `huggingface`, `openrouter`, or `gemini` — selects which chat client `ClaudeAgentService`'s tool-use loop talks to. |
| `ANTHROPIC_API_KEY` / `OPENROUTER_API_KEY` / `GEMINI_API_KEY` / `HF_TOKEN` | API key for whichever provider you set above. |
| `EMBEDDING_PROVIDER` | `openai` (default) or `gemini` — independent of the chat provider; used for product/knowledge-base semantic search. |
| `OPENAI_API_KEY` | Needed if `EMBEDDING_PROVIDER=openai` (the default). |
| `QDRANT_URL`, `QDRANT_COLLECTION` | Vector store for the RAG knowledge base — run `docker compose up -d qdrant` to get one locally. |

**Payment gateways** (`JAZZCASH_*`, `EASYPAISA_*`, `CARD_GATEWAY_*`) are stubbed adapters in this codebase — Cash on Delivery is fully functional without any of them; the others exist as a fixed interface (`PaymentGatewayInterface`) ready for real credentials later.

None of the above needs to be filled in to run the test suite — `phpunit.xml` overrides the WhatsApp/AI variables with fixed test values and runs against an in-memory SQLite database.

## Running the app locally

**Option A — Docker for the backing services, Laravel on your host:**

```bash
docker compose up -d          # MySQL, Redis, and Qdrant
composer run dev              # web server + queue worker + log tailer + Vite, all in one terminal
```

`composer run dev` runs `php artisan serve`, `php artisan queue:listen`, `php artisan pail` (log viewer), and `npm run dev` concurrently. The app will be at `http://localhost:8000`.

**Option B — everything manual:**

```bash
php artisan serve
php artisan queue:work        # required — AI replies and WhatsApp sends happen on the queue
npm run dev                   # in a separate terminal, for hot-reloading assets
```

**Exposing your webhook to Meta during development:** Meta needs a public HTTPS URL to POST webhooks to and to fetch product images from. Use a tunnel (ngrok, Cloudflare Tunnel, etc.) pointed at your local server, and set that URL as both your Meta app's webhook callback URL and `PUBLIC_ASSET_BASE_URL` in `.env`.

## Demo data & login credentials

`php artisan db:seed` (or `migrate --seed`) creates:

- Vendor packages: Basic, Standard, Premium (see `database/seeders/VendorPackageSeeder.php` for exact limits)
- A super admin: **admin@example.com** / **password**
- A demo approved vendor ("Biryani House", two store locations in Karachi) owned by **owner@biryanihouse.test** / **password**, with sample categories, products, and stock

Log in at `/login` with either account to see the admin panel (`/admin`) or vendor panel (`/vendor`) respectively. These are development-only fixtures — never seed them into a production database.

## Running the tests

```bash
composer test
# or directly:
php artisan test
```

The suite runs against an in-memory SQLite database (configured in `phpunit.xml`) with the queue, cache, mail, and session drivers all swapped to synchronous/array equivalents, and fixed dummy values for the WhatsApp/AI environment variables — so `php artisan test` works out of the box with no `.env` setup, no external services, and no real API keys. As of this writing it's **222 tests / 504 assertions**, all passing.

Tests are organized as:

- `tests/Unit/Services` — pure logic: state machine transitions, cart/checkout/order math, store-distance calculations, package limit enforcement, AI tool schema/registry.
- `tests/Unit/Support` — small standalone helpers (e.g. log masking of secrets).
- `tests/Feature/Admin`, `tests/Feature/Vendor` — Livewire component behavior and access control for the admin and vendor panels.
- `tests/Feature/Ai`, `tests/Feature/Jobs` — the conversation agent and the queued jobs that drive it, with the LLM/HTTP calls faked.
- `tests/Feature/Webhook`, `tests/Feature/WhatsApp` — inbound webhook signature verification, idempotency, and catalog sync.
- `tests/Feature/EdgeCases`, `tests/Feature/RateLimitingTest.php`, `tests/Feature/SecurityAuditTest.php` — cross-cutting checks (stock races, cross-vendor access attempts, throttling).

Run a single file or filter by name the normal Artisan/PHPUnit way, e.g.:

```bash
php artisan test tests/Unit/Services/CheckoutServiceTest.php
php artisan test --filter=idempotent
```

## Code style

This project uses [Laravel Pint](https://laravel.com/docs/pint) for formatting. Before committing:

```bash
vendor/bin/pint          # auto-fix
vendor/bin/pint --test   # check only, no changes — this is what CI runs
```

CI (`.github/workflows/ci.yml`) runs the full test suite against MySQL/Redis on every push and PR to `main`/`develop`, plus a separate Pint check — both must be green to merge.

## Artisan commands

Beyond the Laravel defaults, this project adds:

| Command | Purpose |
|---|---|
| `php artisan whatsapp:flow:generate-keys [--force]` | Generates the RSA keypair used to encrypt/decrypt WhatsApp Flow data-exchange requests (the multi-select product picker). |
| `php artisan whatsapp:flow:register` | Registers the public key with Meta and creates/publishes the product-picker Flow. |
| `php artisan whatsapp:send-test` | Sends a real WhatsApp message via the Cloud API — useful for manually confirming your credentials work end-to-end. |
| `php artisan products:reindex-all` | Re-queues every product for embedding/re-indexing — use after switching embedding providers/models, or to recover products left un-indexed by an exhausted embedding-provider quota. |

## Project structure

```
app/
├── AI/                 # Embedding + ingestion + prompt + retrieval logic for the RAG knowledge base
├── Console/Commands/   # WhatsApp Flow key/registration/test-send commands
├── Http/Controllers/   # Webhook receiver, auth
├── Jobs/               # ProcessInboundWhatsAppMessage, RunConversationAgent, notification jobs
├── Livewire/           # Admin and vendor panel components (not shown above — see routes/web.php)
├── Models/             # One Eloquent model per table
├── Policies/           # Per-resource ownership checks (vendor A can never touch vendor B's data)
├── Services/
│   ├── Ai/             # ClaudeAgentService + provider clients (OpenRouter/Gemini/HF), ToolRegistry
│   ├── Catalog/        # Product search
│   ├── Commerce/       # Cart, checkout, order logic
│   ├── Payment/        # PaymentGatewayInterface + COD/JazzCash/Easypaisa/Card adapters
│   ├── Store/           # Nearest-store spatial lookups
│   ├── Vendor/         # Vendor approval, package limit enforcement
│   └── WhatsApp/       # Sending messages, Flow crypto, provisioning
├── Traits/BelongsToVendor.php   # Global scope enforcing vendor_id isolation
database/
├── migrations/         # One per table, ordered by FK dependency
├── factories/          # Used by tests and seeders
└── seeders/            # Roles, vendor packages, demo restaurant data
tests/
├── Unit/               # Fast, no HTTP/DB-heavy logic
└── Feature/            # Livewire components, webhooks, jobs, cross-cutting security/rate-limit checks
PLAN.md                 # Full architecture, schema, API, and design-decision reference
```

## Contributing

Bug reports, feature suggestions, and pull requests are welcome. Please read [`CONTRIBUTING.md`](CONTRIBUTING.md) before opening a PR — it covers the branch workflow, coding standards, and what's expected of a test.

## License

This project is open-sourced software licensed under the [MIT license](LICENSE).
