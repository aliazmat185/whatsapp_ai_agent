# WhatsApp Multi-Vendor Commerce Platform — Implementation Plan

Stack locked: **Laravel (PHP 8.3) + MySQL 8 (with spatial support) + Redis (queues/cache) + Laravel Livewire (admin/vendor panels) + Anthropic Claude API (AI agent) + Meta WhatsApp Cloud API**.

Decisions locked from clarification round (do not revisit without explicit request):

| # | Decision | Choice |
|---|---|---|
| 1 | WhatsApp number scope | **1 number per vendor account** (not per store). AI resolves store via location. |
| 2 | Backend stack | **Laravel + MySQL** |
| 3 | AI engine | **Claude API** driving a **custom conversation-state machine** (DB-backed state, not free-form chat loop) |
| 4 | Payments | **COD fully functional now.** JazzCash/Easypaisa/Card built as pluggable `PaymentGateway` adapter interface, stubbed, marked pending real merchant creds |
| 5 | Vendor approval | **Manual admin approval** required before vendor goes live / WA number activates |
| 6 | Panel frontend | **Laravel Blade + Livewire** (monolith, no separate SPA for v1) |
| 7 | Tenancy | **Shared DB, row-level vendor scoping** (`vendor_id` FK everywhere) |
| 8 | WA number provisioning | **Platform-managed shared WABA.** Platform owns one Meta Business Manager + one WhatsApp Business Account (WABA) as "Tech Provider". Admin/system registers a new phone number under that WABA per vendor via Meta Graph API. Vendor never touches Meta Business Manager, App dashboard, or tokens — vendor only supplies a business name + a phone number to register (or platform assigns one from a pre-purchased pool). |

Assumptions (marked explicitly, kept configurable — flag if wrong):

- **A1**: One WhatsApp Cloud API app/number maps to exactly one vendor. Multiple stores under that vendor share the number; AI/location picks the store.
- **A2**: Package limits (store count, product count, staff count, AI on/off, WA number count, order limits) are enforced at write-time (blocked with error), not just displayed.
- **A3**: "Staff/users per package" implies a lightweight `vendor_staff` role scoped to one vendor (not built in Phase 1 UI, but schema supports it from day one).
- **A4**: Currency is single-currency (PKR) per deployment, configurable via `.env`, not per-vendor multi-currency in v1.
- **A5**: "Nearest store" uses Haversine distance on lat/lng (MySQL spatial `ST_Distance_Sphere`), no external geocoding provider required unless customer sends free-text address (then Google Geocoding API optional, off by default).
- **A6**: WhatsApp Flows (native forms) used for cart/checkout confirmation where practical; free-text NLU via Claude is the fallback for anything Flows can't express (product search, general Q&A).
- **A7**: Order cancellation by customer is allowed only pre-"preparing" status; enforced as business rule, configurable per vendor package later.
- **A8**: Single AI agent language: English + Urdu (Roman Urdu tolerant via Claude's natural handling), no dedicated translation layer in v1.
- **A9**: "Escalate to human" = order/conversation flagged `needs_attention`, vendor notified via WhatsApp template + dashboard badge; no live agent hand-off UI beyond that in v1.
- **A11**: Platform holds ONE Meta App + ONE System User permanent token with `whatsapp_business_management` + `whatsapp_business_messaging` scopes (the credentials you already supplied are treated as this platform-level System User token for dev/first-vendor, not a per-vendor token). Per-vendor `whatsapp_accounts.access_token` field still exists for a future "vendor brings their own WABA" option (Enterprise tier), but default path in v1 is: platform token used for ALL vendors, vendor-level row just stores which `phone_number_id` belongs to them.
- **A12**: New vendor phone numbers are either (a) a number the vendor already owns and gives to admin to register (must not be already active on personal WhatsApp/Business App — Meta requirement), or (b) platform maintains a small pool of pre-purchased SIM/virtual numbers assigned on approval. Default assumed: **(a) vendor-supplied number**, since sourcing/maintaining a number pool is a business/ops decision outside engineering scope — flag if (b) is actually wanted.

---

## 1. Architecture Overview

```
                                   ┌─────────────────────────┐
                                   │   Meta WhatsApp Cloud    │
                                   │   Business API            │
                                   └────────────┬─────────────┘
                                                │ webhook (HTTPS POST)
                                                ▼
                                   ┌─────────────────────────┐
                                   │  Laravel App (monolith)  │
                                   │                          │
   Admin Panel (Livewire) ───────►│  Controllers → Services   │◄─────── Vendor Panel (Livewire)
                                   │        │                  │
                                   │        ▼                  │
                                   │  Queue Dispatch (Redis)   │
                                   └────────────┬─────────────┘
                                                │
                     ┌──────────────────────────┼──────────────────────────┐
                     ▼                          ▼                          ▼
          ┌────────────────────┐   ┌────────────────────────┐  ┌────────────────────┐
          │ WebhookProcessing   │   │  AiConversation Job     │  │ OrderNotification   │
          │ Job (parse payload) │   │  (calls Claude API,      │  │ Job (send WA msgs   │
          │                     │   │  updates conv. state)    │  │  to vendor/customer)│
          └─────────┬───────────┘   └───────────┬─────────────┘  └──────────┬───────────┘
                     │                           │                          │
                     ▼                           ▼                          ▼
          ┌────────────────────────────────────────────────────────────────────┐
          │                        MySQL 8 (shared DB, row-scoped)              │
          │  users, vendors, stores, products, inventories, conversations,      │
          │  conversation_states, carts, orders, payments, webhook_logs, ...    │
          └────────────────────────────────────────────────────────────────────┘
                     │
                     ▼
          ┌────────────────────────┐
          │  Redis (queue, cache,   │
          │  rate limiting)         │
          └────────────────────────┘
```

**Why this shape:**
- Webhook must ACK Meta in <5s → controller only validates + enqueues raw payload, all parsing/AI work happens async in queued jobs (`webhook_logs` row created synchronously for idempotency check, everything else deferred).
- AI agent is a **service**, not a chatbot loop — `ConversationStateMachine` service owns state transitions; Claude is called with structured system prompt + tool definitions (function calling) to fetch products/stores, never allowed to freeform-write to DB directly.
- Single Laravel monolith keeps v1 simple; modules separated by **domain folders**, not microservices, so it can be split later if needed.

---

## 2. Roles & Permissions

Using Spatie `laravel-permission` package.

| Role | Scope | Key abilities |
|---|---|---|
| `super_admin` | Platform-wide | Full access: packages, vendor approval, all data, settings, logs |
| `admin_staff` | Platform-wide (optional, A3) | Subset of admin abilities, no billing/settings |
| `vendor_owner` | Own vendor only | Everything under own vendor: stores, products, orders, WA setup, staff |
| `vendor_staff` | Own vendor, scoped further to assigned store(s) (A3) | Product/order management for assigned store(s) only, no billing/WA config |
| `customer` | No panel login — identified by WhatsApp phone number only | N/A — interacts purely via WhatsApp; no dashboard account required for v1 |

**Enforcement mechanism:**
- Route middleware: `role:super_admin`, `role:vendor_owner|vendor_staff`.
- Policy classes (`StorePolicy`, `ProductPolicy`, `OrderPolicy`) check `$model->vendor_id === auth()->user()->vendor_id` on every resource — hard blocks cross-vendor access even if role passes.
- Global scope `BelongsToVendor` trait auto-applies `where('vendor_id', ...)` on all vendor-scoped Eloquent queries when authenticated as vendor.

---

## 3. Database Schema

MySQL 8, InnoDB, `utf8mb4`. All tables: `id` (BIGINT UNSIGNED PK), `created_at`, `updated_at`; soft-deletes (`deleted_at`) on: vendors, stores, products, orders.

### 3.1 Identity & Access

**users**
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| name | varchar | |
| email | varchar unique nullable | nullable — vendor_staff may be phone-only |
| phone | varchar unique nullable | |
| password | varchar | hashed |
| vendor_id | bigint FK nullable | null for super_admin/admin_staff |
| is_active | boolean default true | |
| last_login_at | timestamp nullable | |

**roles / permissions / model_has_roles** — Spatie package standard tables.

### 3.2 Vendor & Subscription

**vendor_packages**
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| name | varchar | basic / standard / premium / custom |
| slug | varchar unique | |
| price | decimal(10,2) | per billing cycle |
| billing_cycle | enum(monthly, yearly) | |
| max_stores | int | -1 = unlimited |
| max_products | int | -1 = unlimited |
| max_staff_users | int | |
| max_whatsapp_numbers | int | default 1 |
| max_orders_per_month | int | -1 = unlimited |
| ai_features_enabled | boolean | |
| analytics_access | boolean | |
| enabled_payment_methods | json | e.g. `["cod","jazzcash"]` — allowed set for this tier |
| is_active | boolean | admin can retire a package |

**vendors**
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| owner_user_id | bigint FK → users | |
| business_name | varchar | |
| business_type | varchar nullable | |
| vendor_package_id | bigint FK → vendor_packages | |
| status | enum(pending, approved, rejected, suspended) default pending | |
| approved_at | timestamp nullable | |
| approved_by | bigint FK → users nullable | |
| rejection_reason | text nullable | |
| suspended_reason | text nullable | |
| default_currency | varchar(3) default 'PKR' | A4 |
| timezone | varchar default 'Asia/Karachi' | |

**package_subscriptions**
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| vendor_id | bigint FK | |
| vendor_package_id | bigint FK | snapshot reference |
| starts_at | timestamp | |
| ends_at | timestamp nullable | null = ongoing until cancelled |
| status | enum(active, expired, cancelled) | |
| price_at_purchase | decimal(10,2) | audit trail if package price changes later |

**vendor_approvals** (audit trail, separate from vendors.status for history)
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| vendor_id | bigint FK | |
| action | enum(submitted, approved, rejected, suspended, reactivated) | |
| performed_by | bigint FK → users nullable | null = system |
| reason | text nullable | |
| created_at | timestamp | |

### 3.3 Stores & WhatsApp

**stores**
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| vendor_id | bigint FK | |
| name | varchar | |
| slug | varchar | unique per vendor |
| is_active | boolean default true | |
| is_primary | boolean default false | fallback store when no nearby match |
| working_hours | json nullable | `{"mon":{"open":"09:00","close":"21:00"}, ...}` |
| created_at / updated_at | | |

**store_locations**
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| store_id | bigint FK unique | 1:1 with store |
| address_line | text | |
| city | varchar | |
| latitude | decimal(10,7) | |
| longitude | decimal(10,7) | |
| point | POINT (spatial, SRID 4326) generated/synced from lat/lng | for `ST_Distance_Sphere` queries |

Spatial index: `SPATIAL INDEX(point)`.

**whatsapp_accounts**
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| vendor_id | bigint FK unique | 1 per vendor per A1/decision-1 |
| phone_number_id | varchar | Meta's `phone_number_id` — **unique per vendor, issued by admin provisioning flow** |
| waba_id | varchar | the **shared platform WABA ID** (same value across most vendors, per decision-8) |
| display_phone_number | varchar | human-readable, shown in vendor panel |
| access_token | text encrypted nullable | **null in default v1 path** — platform-level token from `.env`/`system_settings` used instead (A11); populated only if a future Enterprise vendor brings their own WABA |
| uses_platform_token | boolean default true | flips false only for bring-your-own-WABA vendors |
| onboarding_status | enum(number_submitted, verifying, registered, active, failed, disabled) | tracks the provisioning pipeline, not just connectivity |
| verification_method | enum(sms, voice) nullable | how Meta sent the OTP during number registration |
| rejection_reason | text nullable | if Meta registration fails (e.g. number already on WhatsApp) |
| status | enum(pending, active, disabled) | must be `active` + vendor `approved` to receive traffic |
| connected_at | timestamp nullable | |

> **Note on config values supplied**: the `phone_number_id` + `permanent_access_token` you already have are the **platform System User credentials** (A11) — stored once in `.env`/`system_settings`, used for every vendor's API calls (send message, register number). They seed the **first vendor** end-to-end during dev. See §5a for the provisioning flow and §14 Environment Variables.

### 3.4 Catalog

**product_categories**
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| vendor_id | bigint FK | categories are vendor-scoped, not global |
| parent_id | bigint FK nullable self | supports subcategories |
| name | varchar | |
| slug | varchar | |
| is_active | boolean | |

**products**
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| vendor_id | bigint FK | denormalized for fast scoping |
| store_id | bigint FK | product belongs to exactly one store (per reqs "product must belong to a store") |
| category_id | bigint FK nullable | |
| name | varchar | |
| slug | varchar | |
| description | text nullable | |
| base_price | decimal(10,2) | |
| sku | varchar nullable | |
| is_active | boolean default true | |
| ai_search_keywords | text nullable | optional curated keywords to aid AI matching |

**product_images**
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| product_id | bigint FK | |
| path | varchar | storage disk path / URL |
| is_primary | boolean | |
| sort_order | int | |

**product_variants** (e.g. size/flavor/color — optional per product)
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| product_id | bigint FK | |
| name | varchar | e.g. "Large / Spicy" |
| sku | varchar nullable | |
| price_delta | decimal(10,2) default 0 | added to base_price |
| is_active | boolean | |

**inventories** (stock per store — note product already has 1 store_id in this model; table still kept distinct to cleanly support future "same product multiple stores" without schema change, and to track variant-level stock)
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| store_id | bigint FK | |
| product_id | bigint FK | |
| product_variant_id | bigint FK nullable | null = tracks base product stock |
| quantity | int | |
| low_stock_threshold | int default 5 | |
| track_stock | boolean default true | some products may be "always available" services |
| updated_at | | |

### 3.5 Conversation & AI

**conversations**
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| vendor_id | bigint FK | resolved on first inbound message |
| store_id | bigint FK nullable | resolved once location/intent known |
| customer_phone | varchar | E.164 |
| customer_name | varchar nullable | from WA profile |
| customer_lat | decimal(10,7) nullable | |
| customer_lng | decimal(10,7) nullable | |
| status | enum(active, awaiting_customer, needs_attention, closed) | |
| last_message_at | timestamp | |

**conversation_messages**
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| conversation_id | bigint FK | |
| direction | enum(inbound, outbound) | |
| message_type | enum(text, image, location, interactive, template, order) | |
| wa_message_id | varchar unique nullable | Meta's message ID — **idempotency key** |
| content | json | raw normalized payload (text body / lat-lng / interactive reply id) |
| ai_generated | boolean default false | |
| created_at | timestamp | |

Unique index on `wa_message_id` (nullable-unique) → duplicate webhook delivery of same message ID is rejected at insert.

**conversation_states** (structured state machine — the core of "not just raw chat replies")
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| conversation_id | bigint FK unique | 1:1 current state |
| current_step | enum(greeting, awaiting_location, browsing, cart_review, awaiting_payment_method, awaiting_confirmation, order_placed, support_escalation) | |
| context | json | working memory: selected category, candidate store_ids, pending cart_id, last shown product_ids, etc. |
| updated_at | | |

**ai_routing_logs**
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| conversation_id | bigint FK | |
| conversation_message_id | bigint FK | which inbound message triggered this |
| detected_intent | varchar | product_search / store_selection / cart / checkout / order_status / support / unknown |
| resolved_vendor_id | bigint FK nullable | |
| resolved_store_id | bigint FK nullable | |
| claude_request_payload | json | prompt + tools sent (tokens/messages truncated for storage) |
| claude_response_payload | json | raw response incl. tool_use blocks |
| latency_ms | int | |
| escalated | boolean default false | |
| created_at | timestamp | |

### 3.6 Cart, Orders, Payments

**carts**
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| conversation_id | bigint FK | |
| vendor_id | bigint FK | |
| store_id | bigint FK | |
| status | enum(open, converted, abandoned) | |

**cart_items**
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| cart_id | bigint FK | |
| product_id | bigint FK | |
| product_variant_id | bigint FK nullable | |
| quantity | int | |
| unit_price | decimal(10,2) | snapshot at add-time |

**orders**
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| order_number | varchar unique | human-readable, e.g. `ORD-20260702-0001` |
| vendor_id | bigint FK | |
| store_id | bigint FK | |
| conversation_id | bigint FK | |
| cart_id | bigint FK | |
| customer_phone | varchar | |
| customer_name | varchar nullable | |
| customer_address | text nullable | for delivery |
| customer_lat / customer_lng | decimal nullable | |
| subtotal | decimal(10,2) | |
| delivery_fee | decimal(10,2) default 0 | |
| total | decimal(10,2) | |
| payment_method | enum(cod, jazzcash, easypaisa, card) | |
| payment_status | enum(pending, paid, failed, refunded) default pending | |
| status | enum(pending, confirmed, preparing, out_for_delivery, completed, cancelled) default pending | |
| needs_attention | boolean default false | escalation flag, A9 |
| cancelled_reason | text nullable | |
| created_at | | |

**order_items**
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| order_id | bigint FK | |
| product_id | bigint FK | |
| product_variant_id | bigint FK nullable | |
| product_name_snapshot | varchar | preserve name at time of order |
| quantity | int | |
| unit_price | decimal(10,2) | |
| line_total | decimal(10,2) | |

**payments**
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| order_id | bigint FK unique | |
| method | enum(cod, jazzcash, easypaisa, card) | |
| status | enum(pending, processing, paid, failed, refunded) | |
| amount | decimal(10,2) | |
| gateway_reference | varchar nullable | external txn ref once integrated |

**payment_transactions** (raw gateway event log, append-only — separate from `payments` current-state row)
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| payment_id | bigint FK | |
| event_type | varchar | e.g. `initiated`, `webhook_received`, `confirmed`, `failed` |
| raw_payload | json | |
| created_at | | |

### 3.7 Platform / Ops

**webhook_logs**
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| source | enum(whatsapp, payment_gateway) | |
| wa_message_id | varchar nullable index | idempotency check point |
| raw_payload | json | full untouched body |
| processing_status | enum(received, processed, ignored_duplicate, failed) | |
| error_message | text nullable | |
| received_at | timestamp | |

**notifications**
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| notifiable_type / notifiable_id | morph | vendor, user, etc. |
| type | varchar | `new_order`, `vendor_approved`, `low_stock`, ... |
| channel | enum(whatsapp, dashboard, email) | |
| payload | json | |
| read_at | timestamp nullable | |

**system_settings** (admin-configurable, key-value — not explicitly listed but required by "configure system-wide settings")
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| key | varchar unique | e.g. `payment_methods.jazzcash.enabled` |
| value | json | |

### 3.8 Entity Relationship Summary

```
vendor_packages ─┬─< package_subscriptions >─┬─ vendors ─┬─< vendor_approvals
                  │                            │           ├─< stores ─┬─< store_locations (1:1)
                  │                            │           │           ├─< products ─┬─< product_images
                  │                            │           │           │              ├─< product_variants
                  │                            │           │           │              └─< inventories >─┐
                  │                            │           │           └─< inventories ─────────────────┘
                  │                            │           ├─< whatsapp_accounts (1:1)
                  │                            │           ├─< product_categories
                  │                            │           └─< users (staff)
                  │                            └─ users (owner)
                  └── (referenced by)
conversations ─┬─< conversation_messages
               ├─< conversation_states (1:1)
               ├─< ai_routing_logs
               ├─< carts ─< cart_items
               └─< orders ─┬─< order_items
                            ├─< payments (1:1) ─< payment_transactions
                            └─< notifications (via morph)
```

---

## 4. API Endpoints

Base: `/api/v1`. Auth: Laravel Sanctum (token-based) for admin/vendor panels' underlying API + mobile-readiness; Livewire uses session auth directly for the Blade panels but same Service/Controller layer underneath.

### 4.1 Admin (`/api/v1/admin`, middleware: `auth:sanctum`, `role:super_admin`)

| Method | Endpoint | Purpose |
|---|---|---|
| GET/POST | `/packages` | list / create package |
| GET/PUT/DELETE | `/packages/{id}` | view/update/retire package |
| GET | `/vendors` | list all vendors (filter: status, package) |
| GET | `/vendors/{id}` | vendor detail incl. stores/products summary |
| POST | `/vendors/{id}/approve` | approve vendor |
| POST | `/vendors/{id}/reject` | reject vendor `{reason}` |
| POST | `/vendors/{id}/suspend` | suspend vendor `{reason}` |
| POST | `/vendors/{id}/reactivate` | reactivate vendor |
| GET | `/dashboard/stats` | platform-wide analytics (vendor count, order volume, revenue by package) |
| GET | `/webhook-logs` | paginated, filterable webhook log viewer |
| GET | `/webhook-logs/{id}` | raw payload detail |
| GET | `/ai-routing-logs` | paginated AI decision log viewer |
| GET | `/ai-routing-logs/{id}` | full request/response detail |
| GET/PUT | `/settings` | system settings (payment methods on/off, etc.) |

### 4.2 Vendor (`/api/v1/vendor`, middleware: `auth:sanctum`, `role:vendor_owner|vendor_staff`)

| Method | Endpoint | Purpose |
|---|---|---|
| POST | `/auth/register` | vendor self-registration (creates user + vendor, status=pending) |
| POST | `/auth/login` | |
| GET | `/me` | current vendor profile + package limits + usage |
| GET/POST | `/stores` | list/create store (blocked if `max_stores` reached) |
| GET/PUT/DELETE | `/stores/{id}` | policy-checked ownership |
| PUT | `/stores/{id}/location` | update lat/lng/address |
| PUT | `/stores/{id}/working-hours` | |
| GET/POST | `/products` | list/create (blocked if `max_products` reached); query params: `store_id`, `category_id` |
| GET/PUT/DELETE | `/products/{id}` | |
| POST | `/products/{id}/images` | upload |
| PUT | `/products/{id}/variants` | bulk upsert |
| GET/PUT | `/inventories` | list/update stock levels, filter by store |
| GET/POST | `/whatsapp-account` | view/connect WA number (blocked if package `max_whatsapp_numbers` reached, or vendor not approved) |
| GET | `/orders` | list, filter by store/status/date |
| GET | `/orders/{id}` | full detail |
| PUT | `/orders/{id}/status` | transition status (validated against allowed transitions) |
| GET | `/conversations` | list conversations linked to vendor's stores |
| GET | `/conversations/{id}/messages` | thread view |
| GET | `/analytics/store/{id}` | store-wise sales summary |
| GET | `/dashboard/stats` | today's orders, pending count, sales summary, active stores |

### 4.3 Customer / WhatsApp-driven (`/api/v1/webhook`, `/api/v1/customer` — mostly internal, invoked by AI service not directly by end-user browser)

| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/webhook/whatsapp` | Meta webhook verification (`hub.challenge` echo) |
| POST | `/webhook/whatsapp` | inbound message events — **public, signature-verified** |
| POST | `/customer/location` | (internal, used by AI service) persist shared location to conversation |
| GET | `/customer/products/search` | (internal) search products by store/category/keyword/price range |
| POST | `/customer/cart/items` | (internal) add/update cart item |
| DELETE | `/customer/cart/items/{id}` | remove |
| POST | `/customer/checkout` | (internal) validate cart + create order |
| GET | `/customer/orders/{order_number}/track` | order status lookup, also exposed as a WA reply shortcut "track my order" |

> These "customer" endpoints are called **server-side by the AI orchestration service**, not exposed to public internet browsers — WhatsApp is the only customer-facing surface. Kept as internal REST endpoints (not raw service calls) so behavior is independently testable via Postman/CI without needing live WhatsApp round-trips.

---

## 5. Webhook Handling

**Endpoint**: `POST /api/v1/webhook/whatsapp`

Flow:
1. **Verify (GET)**: Meta calls with `hub.mode`, `hub.verify_token`, `hub.challenge` → compare token to `.env WHATSAPP_WEBHOOK_VERIFY_TOKEN`, echo challenge if match, else 403.
2. **Receive (POST)**:
   - Verify `X-Hub-Signature-256` header (HMAC-SHA256 of raw body using **App Secret**) — reject with 403 if mismatch. Non-negotiable security step.
   - Immediately insert `webhook_logs` row with `raw_payload`, `processing_status = received`. This DB insert is the transaction boundary — return `200 OK` to Meta right after, **before** any processing, to avoid Meta retry storms on slow processing.
   - Extract `wa_message_id` (Meta's `messages[0].id`) from payload for idempotency:
     - If a `conversation_messages` row with that `wa_message_id` already exists → mark log `ignored_duplicate`, stop. (Handles Meta's at-least-once delivery retries.)
   - Dispatch `ProcessInboundWhatsAppMessage` job to Redis queue (async) with the `webhook_logs.id`.
3. **Job: `ProcessInboundWhatsAppMessage`**:
   - Re-check idempotency inside job too (belt-and-suspenders — race between two webhook deliveries hitting queue near-simultaneously).
   - Parse message type: `text`, `image`/`document` (media — download via Meta media API, store reference), `location` (lat/lng), `interactive` (button/list reply — includes WhatsApp Flow submissions), `order` (WA catalog-native order object, if Meta catalog used — treated as an alternate structured cart-add path).
   - Resolve **vendor** by matching inbound `phone_number_id` (from payload metadata) → `whatsapp_accounts.phone_number_id`. If vendor not `approved` or WA account not `active` → log + auto-reply "service temporarily unavailable", stop (no AI call).
   - Find-or-create `conversations` row keyed by `(vendor_id, customer_phone)`.
   - Insert `conversation_messages` row (this is where `wa_message_id` uniqueness is enforced at DB level as final idempotency guard).
   - Dispatch `RunConversationAgent` job.
4. **Retries**: queue job uses Laravel's default retry/backoff (`tries=3`, exponential backoff); failures land in `failed_jobs` table, visible in admin log viewer via `ai_routing_logs`/`webhook_logs` cross-reference. Meta's own retries are naturally absorbed by the idempotency check in step 2/3.

**Sending messages** (outbound): `WhatsAppService::sendText()`, `::sendInteractiveList()`, `::sendLocationRequest()`, `::sendTemplate()` — thin wrapper over Meta Graph API `POST /{phone_number_id}/messages`, using the **platform System User token** (`.env`/`system_settings`) + the vendor's own `phone_number_id` from `whatsapp_accounts` (per decision-8/A11 — vendor never supplies their own token).

---

## 5a. Vendor WhatsApp Onboarding (non-technical vendor path)

Problem this solves: most vendors have never heard of Meta Business Manager, WABA, App Secrets, or `phone_number_id`. They only know "I want my shop's WhatsApp to auto-reply and take orders." The platform must do all Meta-side plumbing for them.

**Architecture**: platform runs a single Meta **Tech Provider** setup — one Meta Business Manager, one Meta App (with WhatsApp product enabled), one WABA acting as the umbrella under which every vendor's phone number is registered as a child number. This is a standard, supported Meta pattern for SaaS platforms serving many small businesses (sometimes called an "ISV"/BSP-style setup).

**Vendor-facing steps** (zero Meta knowledge required):

1. Vendor completes registration + is approved by admin (existing flow, §9 unchanged).
2. Vendor panel shows **"Connect WhatsApp"** wizard:
   - Step 1: Vendor enters the phone number they want customers to message (their existing number — must not be currently active on the regular WhatsApp/Business consumer app; wizard shows a short plain-language checklist: "Turn off WhatsApp on this number", "This will become your official shop number").
   - Step 2: Vendor picks OTP delivery method (SMS or voice call).
   - Step 3: Vendor enters the OTP code they receive on that phone.
   - Step 4: Done — platform shows "Your WhatsApp is connected!" with a QR code / `wa.me` link the vendor can put on their shop signage/social media.
3. No token, no `phone_number_id`, no App dashboard is ever shown to the vendor. All of that is handled server-side using the platform's own System User credentials.

**Backend flow** (`WhatsAppProvisioningService`):

1. `POST /vendor/whatsapp-account` with `{phone_number: "+9230..."}` → creates `whatsapp_accounts` row, `onboarding_status = number_submitted`.
2. Service calls Meta Graph API (using platform token) to **register the number under the platform WABA**:
   - `POST /{waba_id}/phone_numbers` (add number to WABA) → returns a new `phone_number_id`.
   - `POST /{phone_number_id}/request_code` with chosen `code_method` (sms/voice) → triggers OTP to vendor's phone.
   - `onboarding_status = verifying`.
3. Vendor submits OTP → `POST /vendor/whatsapp-account/verify` → service calls `POST /{phone_number_id}/verify_code` with the code.
   - Success → `onboarding_status = registered`, then `POST /{phone_number_id}/register` (two-step Cloud API activation) → `status = active`, `connected_at = now()`.
   - Failure (wrong OTP, number already in use elsewhere) → `onboarding_status = failed`, `rejection_reason` set, vendor shown a plain-language retry message.
4. From this point, `whatsapp_accounts.phone_number_id` is what inbound webhook payloads carry in `metadata.phone_number_id` — this is how §5's vendor-resolution step works, unchanged.
5. **Webhook subscription**: platform's single Meta App is already subscribed to `messages` webhook field at the App level (done once, by platform admin, during initial Meta setup — not per vendor). Adding a new number under the WABA automatically routes its messages to the same webhook URL — no per-vendor webhook configuration needed.

**What this means for earlier sections** (no contradiction, just clarifying the "vendor" in §5 always means "platform, acting on vendor's behalf"):
- §14 Environment Variables `WHATSAPP_ACCESS_TOKEN` / `WHATSAPP_APP_SECRET` are **platform-level, set once**, not per-vendor.
- `whatsapp_accounts.access_token` column stays nullable, reserved for the future Enterprise "bring your own WABA" tier (A11) — unused in default v1 path.
- Admin panel gains a **"Number Pool / Provisioning" log view**: shows every vendor's onboarding_status, lets admin manually retry a failed registration or view Meta's raw error response (mirrors the webhook_logs/ai_routing_logs pattern already in the plan).

**Edge cases specific to onboarding** (added to §14 Edge Cases table conceptually, listed here for locality):
- Number already registered on personal WhatsApp app → Meta registration fails with a specific error code → surfaced to vendor as "please uninstall WhatsApp from this number first, or use a different number."
- Vendor has no spare number at all → platform may optionally offer a small pool of pre-purchased virtual numbers as a paid add-on (A12-b) — **not built in v1**, flagged as future option only.
- Vendor abandons the wizard mid-OTP → `onboarding_status` stays `verifying` indefinitely; a scheduled command (`ExpireStaleOnboarding`) resets it to `number_submitted` after 24h so they can retry cleanly.

---

## 6. AI Agent Flow

**Principle**: Claude is the **NLU + decision-making layer**, not the source of truth. It receives structured context, is given a fixed set of **tools** (function-calling) it may call, and its final action is written back through our `ConversationStateMachine` service — never directly to the DB.

### 6.1 Components

- `ConversationStateMachine` (service): owns `conversation_states.current_step` transitions; pure PHP, deterministic, testable without any LLM call.
- `ClaudeAgentService` (service): builds system prompt (vendor context, store list, current cart, conversation history window) + tool schema, calls Anthropic Messages API, executes any `tool_use` blocks by delegating to internal services (`ProductSearchService`, `StoreLocatorService`, `CartService`), loops until Claude returns a final text/interactive response (standard tool-use loop, capped at N iterations to avoid runaway cost).
- Tools exposed to Claude:
  - `search_products(store_id, query?, category?, price_min?, price_max?)`
  - `get_store_candidates(vendor_id, lat?, lng?)` → nearest stores ranked
  - `add_to_cart(cart_id, product_id, variant_id?, quantity)`
  - `get_cart(cart_id)`
  - `start_checkout(cart_id)` → returns required-fields checklist
  - `request_location()` → signals system to send WA location-request message
  - `escalate_to_human(reason)` → sets `needs_attention`

### 6.2 Flow per inbound message

1. Load `conversation_states.context` (JSON) + last N messages as short-term memory.
2. If `resolved_store_id` is null and vendor has >1 active store → check for location:
   - If `customer_lat/lng` present on conversation → call `get_store_candidates`, auto-resolve nearest, proceed.
   - If absent → **do not call Claude for product logic yet**; state machine transitions to `awaiting_location`, sends WA "please share your location" (native location-request message), short-circuits.
   - If vendor has exactly 1 active store → auto-resolve immediately, skip location ask (still store it if customer shares later, for delivery address).
3. Otherwise, invoke `ClaudeAgentService::handle($conversation)` with current state + tools.
4. Claude determines intent (product_search / store_selection / cart / checkout / order_status / support) — this classification + chosen tool calls are logged verbatim to `ai_routing_logs`.
5. Service executes tool calls against real DB-backed services (never trusts Claude's own numbers for price/stock — always re-fetches).
6. Final Claude response converted to WhatsApp message(s): plain text, or interactive list/buttons for product selection (structured UI preferred over free text wherever the option set is enumerable, per A6).
7. State machine advances `current_step`; `context` JSON updated with any new facts (selected store, active cart_id, last shown product ids for "add the second one" style follow-ups).
8. If Claude signals low confidence / repeated failure to resolve intent (3 consecutive unresolved turns) → `escalate_to_human` tool auto-invoked → `needs_attention=true` on conversation, vendor notified.

### 6.3 Guardrails

- Claude never computes prices/totals itself — always via `CartService::recalculate()`.
- Claude never marks an order "paid" — payment status only changes via `PaymentService` / gateway webhook.
- Max conversation history sent to Claude: last 20 messages (sliding window) + persistent `context` JSON summary, to bound token cost.
- All tool executions wrapped in vendor/store ownership checks — Claude cannot be prompt-injected into cross-vendor data access since tool implementations hard-filter by `conversation.vendor_id`.

---

## 7. Store and Product Selection Flow

1. Customer messages vendor's WA number → vendor resolved by `phone_number_id`.
2. Vendor has **1 store** → auto-select, skip to product browsing.
3. Vendor has **multiple stores**:
   - No location on file → AI asks for location (native WA location share button).
   - Location received → `StoreLocatorService::nearestStores(vendor_id, lat, lng, limit=3)`:
     - Query: `SELECT *, ST_Distance_Sphere(point, POINT(:lng,:lat)) AS distance FROM store_locations JOIN stores ... WHERE stores.vendor_id = ? AND stores.is_active = 1 ORDER BY distance ASC LIMIT 3`.
     - Optionally re-rank by stock availability if customer already expressed a product interest in the same message (e.g. "do you have iPhone chargers" + location together) — secondary sort key: count of in-stock matching products.
   - If nearest store has 0 matching stock for a specifically-requested item → surface next-nearest store that has it, with a note ("nearest branch is out of stock, but our Gulshan branch 3km away has it").
   - If **no store within reasonable radius** (configurable, default 50km) → fall back to `stores.is_primary = true` store, inform customer delivery may be limited/unavailable, let vendor decide manually (flagged).
4. Once `store_id` resolved on conversation → all subsequent product queries scoped to that store until customer explicitly asks to switch / conversation resets.

---

## 8. Checkout and Payment Flow

1. Customer confirms cart contents (AI shows itemized list + total via interactive message).
2. `start_checkout` tool → `CheckoutService::validate(cart)`:
   - Cart not empty.
   - All items still active + in stock (re-check at checkout time, not just add-time) — insufficient stock items flagged, customer asked to adjust quantity or remove.
   - Store still active, vendor still approved.
3. Required customer info collected if missing: delivery address (text or location pin), name.
4. Payment method selection presented as WA interactive buttons, **filtered to only vendor's `enabled_payment_methods`** (intersection of package-allowed methods ∩ vendor's own `system_settings`/vendor-level toggle).
5. On method selection:
   - **COD**: order created immediately with `payment_status = pending`, `status = pending`. No gateway call.
   - **JazzCash / Easypaisa / Card**: order created with `payment_status = pending`; `PaymentGatewayInterface::initiate()` called → adapter stub returns a mock/placeholder response in current phase (real integration deferred, contract fixed: `initiate($order): PaymentInitiationResult`, `handleCallback($payload): PaymentResult`). Architecture ready to swap in real SDK without touching order/cart logic.
6. `Order` + `OrderItem` rows created transactionally from `Cart`/`CartItem` (snapshotting price/name). Cart marked `converted`.
7. Confirmation message sent to customer with order number + summary.
8. `NotifyVendorOfNewOrder` job dispatched.

---

## 9. Order Routing to Vendor

- Order always carries `vendor_id` + `store_id` at creation (inherited from conversation/cart) — no separate "routing" inference step needed since store was already resolved during conversation.
- `NotifyVendorOfNewOrder` job:
  - WhatsApp template message to vendor's registered contact number (separate from the customer-facing business number — assumption: vendor provides a personal notification number in their profile; **flagged as assumption A10** if not already collected — recommend adding `vendors.notification_phone` column).
  - Dashboard `notifications` row (real-time badge via Livewire polling or broadcast).
  - Order appears in vendor panel's "Today's Orders" and store-filtered order list immediately (DB write is synchronous within the checkout request; notification is the only async part).

---

## 10. Admin Panel Features

- **Dashboard**: total vendors (by status), total orders (platform-wide), revenue by package tier, active conversations count, system health (webhook failure rate).
- **Package management**: CRUD packages, retire (soft) vs delete, see vendor count per package.
- **Vendor management**: list/filter/search, detail view (stores, products, order volume, WA connection status), approve/reject with reason, suspend/reactivate.
- **Webhook log viewer**: filterable table (date, status, vendor), raw payload JSON viewer, reprocess-failed action (re-dispatches job).
- **Number provisioning log** (§5a): per-vendor onboarding_status, manual retry action for failed Meta registrations, raw Meta error viewer.
- **AI routing log viewer**: per-conversation timeline of detected intents, tool calls, latency, escalation flags — critical for debugging AI misroutes.
- **Payment method settings**: global on/off toggles feeding into `system_settings`, independent of per-package allowance (both must be true for a method to actually appear to customer).
- **System settings**: default radius for store search, max AI loop iterations, business hours enforcement toggle, etc.

## 11. Vendor Panel Features

- **Dashboard**: today's orders, pending orders count, sales summary (day/week/month), active stores count, low-stock alerts.
- **Store management**: CRUD, map picker for lat/lng, working hours editor.
- **Product management**: CRUD, category assignment, image upload, variants, per-store stock editor.
- **WhatsApp setup**: guided "Connect WhatsApp" wizard (phone number → OTP method → OTP code → done, per §5a) — no token/phone_number_id ever shown to vendor; connection status indicator, `wa.me` QR code, test-send button.
- **Orders**: list (filterable by store/status/date), detail view, status update buttons (only valid next-transitions shown).
- **Conversations**: list of customer threads linked to vendor's stores, read-only transcript view, "needs attention" filter.
- **Analytics**: store-wise sales, best-selling products, conversation-to-order conversion rate.
- **Package/usage view**: current package limits vs usage (e.g. "3/5 stores used").

## 12. Customer Conversation Flow (WhatsApp-side script)

```
Customer: Hi
Bot:      Welcome to {Vendor Name}! 👋 How can I help — browse products, check an order, or something else?

[if multi-store vendor, no location yet]
Bot:      To show you what's available nearby, please share your location. [Location button]
Customer: [shares location]
Bot:      Found you! Our nearest branch is {Store Name}, {distance} km away. Here's what's popular there: [interactive list of categories/products]

Customer: Do you have chargers?
Bot:      [search_products tool] Yes! Here are 3 options at {Store Name}: [list with price/stock]

Customer: [selects item, quantity]
Bot:      Added 1x {Product} (Rs.{price}) to your cart. Anything else, or ready to checkout?

Customer: Checkout
Bot:      Here's your order: [itemized list + total]. What's your delivery address?
Customer: [address or pin]
Bot:      Choose payment method: [COD] [JazzCash] [Easypaisa] [Card]
Customer: [selects COD]
Bot:      ✅ Order #ORD-20260702-0001 confirmed! Total: Rs.{total}. Payment: Cash on Delivery. We'll notify you once the vendor confirms.
```

Order status check: customer sends "track order" or "where's my order" any time → AI intent = `order_status` → looks up most recent order for `customer_phone` under this vendor, replies with current status.

---

## 13. Validation Rules

- Vendor cannot create store/product if `status != approved`.
- Product creation blocked if `products.count() >= package.max_products` (checked at write, not just UI).
- Store creation blocked if `stores.count() >= package.max_stores`.
- WA account connection blocked if `whatsapp_accounts.count() >= package.max_whatsapp_numbers` or vendor not approved.
- Order cannot be created from an empty cart.
- Checkout requires: non-empty cart, valid delivery address/location, selected payment method that is both package-allowed AND globally enabled in `system_settings`.
- Stock re-validated at checkout time (not just cart-add time) — race condition between add-to-cart and checkout must not oversell.
- Inactive vendor or inactive store → webhook still logged, but AI/order flow short-circuits with "temporarily unavailable" auto-reply; no conversation state advances.
- Duplicate `wa_message_id` → hard-rejected at DB unique constraint + pre-checked in job — guarantees no duplicate `conversation_messages` or downstream duplicate order creation.
- Order status transitions restricted to a fixed allowed-next-state map (e.g. `pending → confirmed|cancelled`, `confirmed → preparing|cancelled`, `preparing → out_for_delivery`, `out_for_delivery → completed`, no transitions out of `completed`/`cancelled`).

---

## 14. Edge Cases

| Case | Handling |
|---|---|
| Customer messages before sharing location, vendor has 3 stores | AI asks for location before showing store-specific catalog; generic "about us" / category-only browsing allowed without location |
| Customer shares stale/inaccurate GPS pin | No special handling beyond documenting as a known limitation — location trusted as given |
| Two webhook deliveries for same message (Meta retry) | Idempotency via `wa_message_id` unique constraint — second insert fails silently, logged `ignored_duplicate` |
| Product goes out of stock between cart-add and checkout | Checkout validation re-checks stock, prompts customer to adjust/remove item before order creation |
| Vendor deletes/deactivates a product mid-conversation | Cart item flagged invalid at checkout validation step, same as stock-out case |
| Vendor suspended while customer mid-checkout | Order creation blocked, customer told to try later, `needs_attention` conversation flagged for admin review |
| AI cannot classify intent after repeated attempts | Escalate to human — `needs_attention=true`, vendor notified, generic "someone will assist you shortly" sent to customer |
| Vendor has 0 stores (misconfigured) | WA account cannot go `active` until ≥1 store exists — enforced at activation step |
| Claude API timeout/error mid-conversation | Job retry (queue backoff); if all retries fail, fallback canned reply "we're experiencing a delay, please try again shortly" + error logged to `ai_routing_logs` with `escalated=true` |
| Customer sends unsupported message type (sticker, voice note) | Politely respond "I can help with text or location messages right now" — no AI call wasted on unparseable content |
| Package downgraded below current usage (e.g. 5 stores, new package max 3) | Existing stores/products NOT deleted; new creation blocked until under limit; flagged in vendor dashboard |
| Same customer phone messages two different vendors | Two independent `conversations` rows — customer identity is `(vendor_id, phone)` scoped, not global |

---

## 15. Security Requirements

- **Webhook**: `X-Hub-Signature-256` HMAC verification against Meta App Secret on every inbound POST; verify-token check on GET handshake.
- **Secrets**: all tokens (`WHATSAPP_ACCESS_TOKEN`, `ANTHROPIC_API_KEY`, `APP_SECRET`, DB creds) in `.env`, never committed; `whatsapp_accounts.access_token` stored with Laravel `encrypted` cast at rest.
- **Log masking**: custom log formatter/middleware redacts token-like values (`access_token`, `Authorization` header) before writing to `webhook_logs`/application logs — store payload with token fields replaced by `***REDACTED***`.
- **AuthN/Z**: Sanctum tokens for API, session guards for Livewire panels; every vendor-scoped controller action passes through a Policy check (`$this->authorize('view', $store)`), not just role middleware, to prevent IDOR (vendor A guessing vendor B's `store_id`).
- **Rate limiting**: `throttle:60,1` on public webhook endpoint (per IP + per `phone_number_id`); stricter throttle on auth endpoints (`throttle:5,1` on login) to slow brute force.
- **Input validation**: Form Request classes on every controller endpoint; webhook payload passed through a dedicated `WhatsAppPayloadValidator` before parsing (reject malformed structure early).
- **Mass-assignment protection**: explicit `$fillable` on all models, no blanket `guarded = []`.
- **CSRF**: standard Laravel CSRF for Blade/Livewire forms; webhook endpoint explicitly excluded from CSRF (public, signature-verified instead).
- **SQL injection**: Eloquent/query builder throughout, no raw string concatenation in queries (spatial queries use parameter binding).

---

## 16. Scalability Considerations

- **Async-first**: all WhatsApp/AI processing queued (Redis + Laravel Horizon for queue monitoring) — webhook endpoint stays fast regardless of AI latency.
- **Read scaling**: add read-replica for MySQL once vendor/order volume grows; Eloquent supports read/write connection split natively.
- **Caching**: product catalog per store cached (Redis, tagged cache, invalidated on product/inventory update) to reduce DB load on repeated AI `search_products` calls within a conversation burst.
- **Horizontal scaling**: stateless app servers behind load balancer; queue workers scaled independently from web servers (AI jobs are the heavier CPU/IO cost, likely need more workers than web dynos).
- **Spatial index**: `SPATIAL INDEX` on `store_locations.point` keeps nearest-store queries fast even with thousands of stores per vendor (unlikely at v1 scale, but cheap to do right now).
- **Multi-tenancy headroom**: shared-DB-row-scoped model chosen now; schema keeps `vendor_id` on nearly every table so a future split to DB-per-large-vendor (if one vendor becomes huge) is a data-migration, not a redesign.
- **Claude cost control**: sliding-window context + capped tool-loop iterations bound per-message cost; consider prompt caching (Anthropic native feature) for the large static system-prompt portion (vendor policies, tool schema) across turns of the same conversation.
- **Idempotency as a scaling enabler**: because duplicate webhook delivery is a non-event (safely ignored), Meta's retry behavior under our own slow periods doesn't cause data corruption — buys operational slack during traffic spikes.

---

## 17. Acceptance Criteria

- [ ] Admin can create a package with configurable limits and see it selectable during vendor assignment.
- [ ] Vendor registration creates a `pending` vendor; vendor cannot connect WhatsApp or receive messages until admin approves.
- [ ] Approved vendor can create ≤ `max_stores` stores; (`max_stores + 1`)th attempt returns a validation error, not a silent success.
- [ ] Vendor can add products scoped to a specific store; product never exists without a `store_id`.
- [ ] Sending a WA text message to the vendor's number results in a `webhook_logs` row, a `conversation_messages` row, and an AI-generated reply within an acceptable latency (target: <10s end-to-end in normal load).
- [ ] Resending the identical Meta webhook payload (simulated retry) does **not** create a second `conversation_messages` row or a duplicate order.
- [ ] For a vendor with ≥2 active stores, a customer with no location on file is asked to share location before store-specific products are shown.
- [ ] Sharing location correctly resolves `conversations.store_id` to the nearest active store via spatial distance query.
- [ ] Customer can add ≥1 product to cart, proceed to checkout, select COD, and receive an `orders` row with correct `vendor_id`/`store_id`/`total`.
- [ ] Selecting JazzCash/Easypaisa/Card creates the order with `payment_status = pending` and invokes the stub gateway adapter without error (real settlement deferred).
- [ ] Vendor sees the new order in their panel immediately after checkout, filterable by store.
- [ ] Vendor can transition order status only through allowed next-states; invalid transition attempts are rejected.
- [ ] Attempting to check out with an item that just went out of stock is blocked with a clear message, not a corrupted order.
- [ ] Admin can view raw webhook payloads and AI routing decisions for any conversation for debugging.
- [ ] All vendor-scoped API/panel routes reject access to another vendor's resources (verified via policy test: vendor A token cannot fetch vendor B's store/order/product by ID).
- [ ] Suspending a vendor immediately stops new orders/conversations from progressing past the "temporarily unavailable" auto-reply.

---

## 18. Folder / Module Structure (Laravel)

```
app/
├── Console/Commands/            # e.g. ExpirePackageSubscriptions, ReprocessFailedWebhooks
├── Http/
│   ├── Controllers/
│   │   ├── Admin/               # PackageController, VendorController, WebhookLogController, AiLogController, SettingsController
│   │   ├── Vendor/               # StoreController, ProductController, InventoryController, WhatsAppAccountController, OrderController, ConversationController
│   │   ├── Webhook/              # WhatsAppWebhookController
│   │   └── Customer/             # internal-only controllers: LocationController, ProductSearchController, CartController, CheckoutController, OrderTrackingController
│   ├── Requests/                 # Form Request validation classes, mirrors controllers
│   ├── Resources/                # API Resource transformers (JSON shape)
│   └── Middleware/               # VerifyWhatsAppSignature, EnsureVendorApproved
├── Livewire/
│   ├── Admin/                    # Dashboard, PackageManager, VendorList, WebhookLogViewer, AiLogViewer
│   └── Vendor/                   # Dashboard, StoreManager, ProductManager, OrderList, ConversationViewer
├── Models/                       # Eloquent models, one per table in §3
├── Policies/                     # StorePolicy, ProductPolicy, OrderPolicy, WhatsAppAccountPolicy
├── Services/
│   ├── WhatsApp/                 # WhatsAppService (send), WhatsAppPayloadParser, WhatsAppSignatureVerifier
│   ├── Ai/                       # ClaudeAgentService, ConversationStateMachine, ToolRegistry
│   ├── Catalog/                  # ProductSearchService, InventoryService
│   ├── Store/                    # StoreLocatorService (spatial queries)
│   ├── Commerce/                 # CartService, CheckoutService, OrderService
│   ├── Payment/                  # PaymentGatewayInterface, CodGateway, JazzCashGateway (stub), EasypaisaGateway (stub), CardGateway (stub), PaymentService
│   └── Vendor/                   # VendorApprovalService, PackageLimitService
├── Jobs/                         # ProcessInboundWhatsAppMessage, RunConversationAgent, NotifyVendorOfNewOrder, ReprocessFailedWebhook
├── Events/ & Listeners/          # OrderPlaced -> NotifyVendorOfNewOrder, VendorApproved -> SendWelcomeMessage
├── Notifications/                # NewOrderNotification (WA + dashboard channels)
└── Traits/                       # BelongsToVendor (global scope helper)

database/
├── migrations/                   # one per table, ordered by FK dependency
├── factories/                    # for testing
└── seeders/                      # PackageSeeder, RoleSeeder, DemoVendorSeeder

config/
├── whatsapp.php                  # phone_number_id, access_token, verify_token, api version
├── anthropic.php                 # api key, model name, max_tokens, tool-loop cap
└── commerce.php                  # default currency, store search radius km, low-stock threshold default

tests/
├── Feature/                      # webhook idempotency, checkout flow, policy access-control tests
└── Unit/                         # StoreLocatorService distance calc, ConversationStateMachine transitions
```

---

## 19. Example Request/Response Payloads

**Meta inbound webhook (text message)** — abbreviated:
```json
{
  "entry": [{
    "changes": [{
      "value": {
        "metadata": { "phone_number_id": "1234567890" },
        "messages": [{
          "id": "wamid.HBgM...",
          "from": "923001234567",
          "type": "text",
          "text": { "body": "Do you have chargers?" }
        }]
      }
    }]
  }]
}
```

**Vendor: create store** — `POST /api/v1/vendor/stores`
```json
{ "name": "Gulshan Branch", "address_line": "Block 5, Gulshan-e-Iqbal", "city": "Karachi", "latitude": 24.9200, "longitude": 67.0930 }
```
Response `201`:
```json
{ "data": { "id": 4, "name": "Gulshan Branch", "is_active": true, "vendor_id": 12 } }
```

**Checkout (internal)** — `POST /api/v1/customer/checkout`
```json
{ "cart_id": 88, "customer_name": "Ahmed", "customer_address": "House 12, St 4", "customer_lat": 24.91, "customer_lng": 67.09, "payment_method": "cod" }
```
Response `201`:
```json
{
  "data": {
    "order_number": "ORD-20260702-0001",
    "status": "pending",
    "payment_status": "pending",
    "total": 2450.00,
    "store": { "id": 4, "name": "Gulshan Branch" }
  }
}
```

**AI routing log entry (admin view)**:
```json
{
  "conversation_id": 501,
  "detected_intent": "product_search",
  "resolved_store_id": 4,
  "tool_calls": [{ "name": "search_products", "input": { "store_id": 4, "query": "charger" } }],
  "latency_ms": 1120,
  "escalated": false
}
```

---

## 20. Environment Variables

```env
# App
APP_URL=
APP_TIMEZONE=Asia/Karachi

# Database
DB_CONNECTION=mysql
DB_HOST=
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=

# Redis / Queue
REDIS_HOST=
QUEUE_CONNECTION=redis

# WhatsApp Cloud API — PLATFORM-LEVEL credentials, used for ALL vendors (decision-8/A11)
WHATSAPP_WABA_ID=                    # the one shared platform WhatsApp Business Account ID
WHATSAPP_SYSTEM_USER_TOKEN=          # your existing permanent_access_token — used to call Graph API on behalf of every vendor
WHATSAPP_APP_SECRET=                 # from Meta App dashboard — required for webhook signature verification
WHATSAPP_WEBHOOK_VERIFY_TOKEN=       # arbitrary string you choose, set same value in Meta webhook config
WHATSAPP_API_VERSION=v20.0
WHATSAPP_DEV_PHONE_NUMBER_ID=        # your existing phone_number_id — seeds the first/dev vendor's whatsapp_accounts row directly, bypassing the OTP wizard for local testing

# Claude / Anthropic
ANTHROPIC_API_KEY=
ANTHROPIC_MODEL=claude-sonnet-5
ANTHROPIC_MAX_TOOL_ITERATIONS=5

# Commerce defaults
DEFAULT_CURRENCY=PKR
STORE_SEARCH_RADIUS_KM=50
DEFAULT_LOW_STOCK_THRESHOLD=5

# Payment gateways (stub — real creds added when integrating)
JAZZCASH_MERCHANT_ID=
JAZZCASH_PASSWORD=
JAZZCASH_INTEGRITY_SALT=
EASYPAISA_STORE_ID=
EASYPAISA_HASH_KEY=
CARD_GATEWAY_PUBLIC_KEY=
CARD_GATEWAY_SECRET_KEY=
```

---

## 21. Implementation Roadmap (Phases)

### Phase 0 — Foundations (setup, no business logic)
- Laravel project init, MySQL + Redis provisioning, Spatie permission package, Sanctum setup.
- Base migrations: users, roles, vendors, vendor_packages, package_subscriptions, vendor_approvals.
- `.env` wiring for supplied `phone_number_id` / `access_token` (dev vendor seed).
- CI pipeline skeleton (test run on push).

### Phase 1 — Vendor & Package Core
- Package CRUD (admin), vendor registration/login, approval workflow.
- `PackageLimitService` + enforcement middleware.
- Admin panel: package manager, vendor list/approve/reject/suspend (Livewire).
- Acceptance: admin can approve a vendor; unapproved vendor blocked from all vendor actions.

### Phase 2 — Stores, Products, Inventory
- Migrations: stores, store_locations (+spatial index), product_categories, products, product_images, product_variants, inventories.
- Vendor panel: store CRUD (with map picker), product CRUD, stock management.
- `StoreLocatorService` unit-tested against known lat/lng fixtures.
- Acceptance: vendor creates multiple stores, adds products per store, respects package limits.

### Phase 3 — WhatsApp Integration Skeleton
- `whatsapp_accounts` migration, `WhatsAppProvisioningService` (§5a): number registration, OTP request/verify, activation via platform System User token.
- Vendor panel: "Connect WhatsApp" wizard (phone number → OTP → done), no Meta jargon exposed.
- Webhook endpoint: verification handshake + signature verification + raw payload logging (`webhook_logs`).
- `VerifyWhatsAppSignature` middleware, idempotency check scaffold.
- Admin panel: number provisioning log view (retry failed registrations).
- Manual test: seed dev vendor directly with supplied `WHATSAPP_DEV_PHONE_NUMBER_ID`, confirm inbound message lands in `webhook_logs`; then test full OTP wizard against a second real number to validate the provisioning path end-to-end.

### Phase 4 — Conversation Persistence & Basic Reply
- Migrations: conversations, conversation_messages, conversation_states.
- `ProcessInboundWhatsAppMessage` job: parse payload, resolve vendor, create/find conversation, store message, send a static acknowledgment reply.
- Idempotency proof: replay same payload, confirm no duplicate row.

### Phase 5 — AI Agent Core
- Migrations: ai_routing_logs.
- `ClaudeAgentService` + tool registry (`search_products`, `get_store_candidates` first, others follow).
- `ConversationStateMachine` implementing steps: greeting → awaiting_location → browsing.
- Integrate location-request flow + `StoreLocatorService` resolution into conversation.
- Acceptance: real WA conversation returns AI-driven, store-aware product answers.

### Phase 6 — Cart & Checkout
- Migrations: carts, cart_items, orders, order_items.
- `CartService`, `CheckoutService`, `OrderService`; extend AI tools with `add_to_cart`, `get_cart`, `start_checkout`.
- Order number generation, stock re-validation at checkout.
- Acceptance: full WA conversation from greeting to placed COD order.

### Phase 7 — Payments (modular, stub gateways)
- Migrations: payments, payment_transactions.
- `PaymentGatewayInterface` + `CodGateway` (real), `JazzCashGateway`/`EasypaisaGateway`/`CardGateway` (stubs).
- Payment method selection in conversation flow, `system_settings` global toggles.
- Acceptance: all 4 payment methods selectable end-to-end (non-COD settle as "pending" pending real integration).

### Phase 8 — Vendor Order Management & Notifications
- Migrations: notifications.
- Vendor panel: order list/detail/status update, `NotifyVendorOfNewOrder` job + WA template message.
- Order status transition guard (allowed-next-state map).
- Acceptance: vendor receives WA notification + dashboard entry within seconds of order creation.

### Phase 9 — Admin Observability
- Admin panel: webhook log viewer, AI routing log viewer, dashboard analytics.
- Log-masking middleware for sensitive fields.
- Acceptance: admin can trace any customer message through to its resulting order or escalation.

### Phase 10 — Escalation, Edge Cases, Hardening
- `escalate_to_human` tool wiring, `needs_attention` surfacing in vendor panel.
- Edge-case handling from §14 (stock races, suspended vendor mid-checkout, unsupported message types).
- Rate limiting, security pass (signature verification audit, policy test suite for cross-vendor access).
- Load/latency testing on webhook → AI reply path.

### Phase 11 — Polish & Production Readiness
- Analytics depth (conversion rates, best sellers).
- Horizon setup for queue monitoring, alerting on failed jobs.
- Documentation, deployment scripts, backup strategy for MySQL.
- Final acceptance criteria sign-off (§17 full checklist).

---

## Open Items Requiring Your Input Before Phase 3

1. **A10**: Confirm vendor needs a separate `notification_phone` (personal number for order alerts) distinct from the customer-facing WA business number — or should order alerts also go through the same WA business number as outbound messages to the vendor?
2. Confirm the supplied `phone_number_id`/`permanent_access_token` belong to a Meta App that already has (or can get) **`whatsapp_business_management` scope + Tech Provider/ISV setup** — this is required for the platform-managed onboarding model (§5a) to programmatically register vendor numbers under one WABA. If the current App is only approved for basic messaging scope, Meta App Review submission is needed before Phase 3's provisioning wizard can go live (dev/test can still proceed using the single supplied number in the meantime).
3. Any existing brand/design system for admin+vendor panels, or default to a plain Tailwind/Livewire starter kit?
4. **A12**: confirm default assumption — vendor supplies their own existing phone number for WhatsApp registration (must remove it from personal WhatsApp app first). If instead platform should offer pre-purchased numbers to vendors who don't have a spare one, that's a separate ops/procurement decision (buying SIMs/virtual numbers) outside engineering scope — flag now if wanted so Phase 3 wizard design accounts for a "request a number" option.

Ready to start Phase 0 on your go-ahead.
