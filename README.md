# Usage Billing — Subscription Billing & Usage-Metering Backend

A Laravel 12 backend that meters customer usage against subscription plans and bills it, for a
multi-tenant SaaS: merchants define plans, their customers subscribe, usage events stream in at
high volume, and at each cycle end the system issues an invoice of **base price + overage**, with
**proration** for mid-cycle starts and **split pricing** for mid-cycle upgrades and downgrades.

| | |
|---|---|
| **Stack** | PHP 8.2, Laravel 12, MySQL 8 / MariaDB 10.4+, database queue & cache (Redis-ready) |
| **Tests** | 91 tests / 274 assertions: PHPUnit, in-memory SQLite |
| **Entry points** | `/dashboard` (UI), `POST /api/usage`, `GET /api/merchants/{id}/dashboard`, `GET /api/merchant`, `GET /api/plans`, `usage:aggregate`, `billing:run` |

---

## Contents

1. [Quick start](#1-quick-start)
2. [API](#2-api)
3. [Architecture](#3-architecture)
4. [Data model](#4-data-model)
5. [Billing rules](#5-billing-rules)
6. [Idempotency, queues and chunking](#6-idempotency-queues-and-chunking)
7. [Caching and invalidation](#7-caching-and-invalidation)
8. [Scaling to 50L+ usage events](#8-scaling-to-50l-usage-events)
9. [Assumptions](#9-assumptions)
10. [Trade-offs made under time pressure](#10-trade-offs-made-under-time-pressure)
11. [What I'd do with more time](#11-what-id-do-with-more-time)
12. [Testing](#12-testing)
13. [AI-assisted development](#13-ai-assisted-development)

---

## 1. Quick start

**Requirements:** PHP 8.2+ (`pdo_mysql`, `pdo_sqlite`, `mbstring`), Composer 2, MySQL 8 or MariaDB 10.4+.
On Windows, XAMPP's bundled server works as-is (it is MariaDB, labelled "MySQL").

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Create the database (default name `usage_billing`, user `root`, no password; change `DB_*` in `.env` if yours differ):

```bash
mysql -u root -e "CREATE DATABASE usage_billing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
```

Migrate and load the demo data:

```bash
php artisan migrate:fresh --seed
```

The seeder builds about 2.5 months of history **relative to today** (about 5,400 usage events, 47 invoices) and prints two API keys:

| Merchant | ID | API key (local demo only) |
|---|---|---|
| Acme Analytics | 1 | `mk_demo_acme_local_only_0000000000000000` |
| Globex Metrics | 2 | `mk_demo_other_local_only_000000000000000` |

Run the app, a queue worker, and the scheduler, each in its own terminal:

```bash
php artisan serve
```

```bash
php artisan queue:work
```

```bash
php artisan schedule:work
```

Open **http://127.0.0.1:8000/dashboard** and click **Use demo key (Acme)**. That button only appears
when `APP_ENV=local`. The page follows the brief's wireframe (§2, *Dashboard page*).

Or use the API directly:

```bash
curl -s http://127.0.0.1:8000/api/merchants/1/dashboard -H "Authorization: Bearer mk_demo_acme_local_only_0000000000000000"
```

```bash
curl -s -X POST http://127.0.0.1:8000/api/usage -H "Authorization: Bearer mk_demo_acme_local_only_0000000000000000" -H "Idempotency-Key: demo-001" -H "Content-Type: application/json" -d "{\"customer_id\":\"cus_001\",\"quantity\":250}"
```

Run the same `POST` again: you get `200` and `"duplicate": true` instead of `201`, and nothing is double-counted.

> **Windows note:** `php artisan serve` without OPcache takes about 1s per request, even for Laravel's
> empty `/up` route (it re-boots the framework from source each time). This is the dev server, not
> the app: the database connects in about 11ms. Under PHP-FPM with OPcache, requests are milliseconds.

---

## 2. API

All endpoints authenticate the **merchant** with `Authorization: Bearer <key>` (or `X-Api-Key: <key>`).
Keys are stored only as SHA-256 hashes. Errors are always JSON.

### `POST /api/usage`: record a usage event

| Field | Where | Rules |
|---|---|---|
| `Idempotency-Key` | header (or `idempotency_key` in body) | required, 8–64 chars `[A-Za-z0-9_-:.]` |
| `customer_id` | body | the merchant's own customer reference (`external_ref`); must have an active subscription |
| `quantity` | body | integer, 1 to 1,000,000 |
| `usage_date` | body | `Y-m-d`, optional (defaults to today, UTC); not in the future, at most 3 days old |

| Status | Meaning |
|---|---|
| `201` | New event stored |
| `200` | Retry of an existing key with the same payload; the original event is returned with `"duplicate": true` |
| `409` | Key already used with a **different** payload (a client bug, rejected rather than hidden) |
| `422` | Validation error, unknown customer, another merchant's customer, or no active subscription |
| `401` | Missing or invalid API key |
| `429` | Rate limited (`Retry-After`, `X-RateLimit-*` headers) |

**Rate limits** (in `config/billing.php`, overridable by env): 6,000/min per merchant **and**
1,200/min per customer. The second limit stops one runaway integration from using up its merchant's
whole budget.

### `GET /api/merchants/{id}/dashboard`

A merchant can read only its own dashboard. Any other id, existing or not, gets the same `403`, so
merchant ids cannot be enumerated.

Real output from the demo seed as of 26 Oct 2026 (lists trimmed):

```jsonc
{
  "data": {
    "merchant_id": 1, "currency": "INR", "as_of": "2026-10-26",
    "summary": {                     // headline cards, across active subscriptions' current cycles
      "active_subscriptions": 24,
      "cycle_used_units": 230189,
      "cycle_included_units": 359320,  // sum of prorated allowances
      "most_subscribed_plan": { "name": "Growth", "billing_cycle": "monthly", "subscriptions": 12 },
      "usage_aggregated_at": "2026-10-26 13:07:34"
    },
    "top_customers": {               // top 5 by usage, 1st of month to today
      "period": { "start": "2026-10-01", "end": "2026-10-26" },
      "customers": [
        { "customer_id": 13, "external_ref": "cus_013", "name": "Rath Group", "plan": "Growth",
          "units": 21537, "included_units": 15000, "percent_of_allowance": 143.6 }
      ]
    },
    "projected_overage": {           // per subscription, for its cycle containing today
      "actual_to_date_minor": 2756880,
      "projected_minor": 4085440,
      "subscriptions_projected_over_allowance": 6
    },
    "churn_risk": {                  // month-to-date vs. the same span last month, drop > 50%
      "threshold_percent": 50,
      "current_period":  { "start": "2026-10-01", "end": "2026-10-26" },
      "previous_period": { "start": "2026-09-01", "end": "2026-09-26" },
      "customers": [
        { "customer_id": 19, "external_ref": "cus_019", "name": "Lubowitz, Dare and Morissette",
          "previous_units": 13164, "current_units": 1873, "drop_percent": 85.8 }
      ]
    },
    "daily_trend": [                 // merchant-wide units per day, last 30 days, gaps filled with 0
      { "date": "2026-10-25", "units": 6115 }, { "date": "2026-10-26", "units": 9680 }
    ]
  }
}
```

All money is in **minor units** (paise), so `4085440` is ₹40,854.40. `percent_of_allowance` is
measured against the customer's **prorated** allowance for the current cycle, using the same figure the
invoice's overage line uses (`BillingCalculator::includedUnits`).

### `GET /api/merchant`

The merchant that the API key belongs to (`id`, `name`, `slug`, `currency`). This lets a client holding only
a key discover its merchant id.

### `GET /api/plans`

The merchant's active plans, served from the cached plan catalogue (§7).

### Dashboard page (`/dashboard`)

A server-rendered Blade page laid out like the brief's wireframe:
- three headline cards (current cycle usage, projected overage revenue, most subscribed plan);
- the top 5 table with a **% of allowance** meter (orange when over);
- the 30-day trend chart, the churn-risk panel, and a system-status panel showing the live cache,
  queue and rate-limit configuration.

It is a **thin client over the JSON API**: no numbers are computed in the view, so everything on screen
is covered by the API tests. Implementation details:
- It has no build step (inline CSS, vanilla JS, an SVG chart), so it runs without `npm`.
- The API key is kept in `sessionStorage` and sent only as a Bearer header, never in the URL.
- Merchant-supplied names are rendered with `textContent`, never `innerHTML`, so a customer name
  cannot inject markup.
- The layout collapses to one column on phones.

### Console

| Command | Schedule | Purpose |
|---|---|---|
| `php artisan usage:aggregate [date]` | hourly | Queue rollups for today and the 3-day late-arrival window (or one date) |
| `php artisan billing:run` | daily 01:00 | Queue one invoicing job per subscription whose cycle has closed |

---

## 3. Architecture

```mermaid
flowchart LR
    Client -->|POST /api/usage| MW[AuthenticateMerchant<br/>→ throttle:usage]
    MW --> REQ[StoreUsageRequest] --> DTO[UsageEventData] --> RU[RecordUsageAction]
    RU -->|INSERT, unique key| UE[(usage_events<br/>raw, append-only)]

    SCH[[Scheduler]] -->|hourly| AJ[AggregateDailyUsage job]
    AJ --> AGG[UsageAggregator] -->|upsert absolute SUMs| DU[(daily_usage<br/>rollup)]
    AGG -.bump version.-> DC[DashboardCache]

    SCH -->|daily| GDI[GenerateDueInvoices job] -->|1 job per subscription| BS[BillSubscription job]
    BS --> GIA[GenerateInvoiceAction]
    GIA -->|re-aggregate customer| AGG
    GIA --> CALC[BillingCalculator<br/>pure maths]
    GIA --> INV[(invoices +<br/>line items)]

    Client2[Client] -->|GET dashboard| MD[MerchantDashboard] --> DU
    MD --> CALC
    MD --> PC[PlanCatalog cache]
```

**Layering.** Controllers are invokable and thin. Each request flows
`FormRequest (validation) → DTO (readonly, HTTP-free) → Action (one use case) → Services`.
Domain rules never live in controllers or models.

| Layer | Classes | Notes |
|---|---|---|
| HTTP | `StoreUsageController`, `ShowDashboardController`, `ListPlansController`, `AuthenticateMerchant`, `StoreUsageRequest` | Tenancy is resolved once in middleware; everything downstream is scoped to that merchant |
| Actions | `RecordUsageAction`, `SubscribeCustomerAction`, `ChangeSubscriptionPlanAction`, `GenerateInvoiceAction` | Transactional use cases; the same row lock serialises plan changes against invoicing |
| Services | `BillingCalculator`, `UsageAggregator`, `MerchantDashboard`, `PlanCatalog`, `DashboardCache` | `BillingCalculator` is **pure** (no DB, clock or config), which makes it trivially unit-testable |
| Jobs | `AggregateDailyUsage`, `GenerateDueInvoices`, `BillSubscription` | All `ShouldBeUnique`, all safe to retry |
| Data | `UsageEventData`, `RecordedUsage`, `DateRange`, `PlanSegment`, `PlanData`, `InvoiceDraft`, `InvoiceLine` | Readonly DTOs / value objects |
| Support | `Money` (integer half-up division), `DateOnly` cast | No floats anywhere near money |

The billing calculator is used **twice**: by invoicing and by the dashboard's projection. The
projected overage is priced by exactly the code that will issue the invoice, so plan changes and
proration can never disagree between the two.

---

## 4. Data model

```mermaid
erDiagram
    merchants ||--o{ plans : defines
    merchants ||--o{ customers : has
    customers ||--o{ subscriptions : holds
    subscriptions ||--o{ subscription_plan_periods : "is priced by (segments)"
    plans ||--o{ subscription_plan_periods : "snapshotted into"
    customers ||--o{ usage_events : "raw (no FK)"
    customers ||--o{ daily_usage : rollup
    subscriptions ||--o{ invoices : "one per cycle"
    invoices ||--o{ invoice_line_items : contains
```

| Table | Key columns | Indexes (and why) |
|---|---|---|
| `merchants` | `currency`, `api_key_hash` | unique `slug`; unique `api_key_hash` (auth lookup on every request) |
| `plans` | `billing_cycle`, `base_price_minor`, `included_units`, `overage_rate_micros`, `is_active` | `(merchant_id, is_active)` |
| `customers` | `external_ref` (the merchant's id) | unique `(merchant_id, external_ref)`, which serves the usage-endpoint lookup |
| `subscriptions` | `status`, `started_on`, `current_period_start/end` | `(status, current_period_end)` for the billing scan; `(customer_id, status)` |
| `subscription_plan_periods` | `starts_on`, `ends_on` (null = open), **pricing snapshot** | `(subscription_id, starts_on)` |
| `usage_events` | `idempotency_key`, `quantity`, `usage_date`, `created_at` | unique `(merchant_id, idempotency_key)`; `(usage_date, customer_id)`; `(customer_id, usage_date)` |
| `daily_usage` | `usage_date`, `total_units`, `event_count` | unique `(customer_id, usage_date)` (upsert target); covering `(merchant_id, usage_date, customer_id, total_units)` for the dashboard |
| `invoices` | `period_start/end`, `base/overage/total_minor`, `currency` | unique `(subscription_id, period_start)`, so a cycle can never be invoiced twice |
| `invoice_line_items` | `type`, `segment_start/end`, `quantity`, `unit_price_micros`, `amount_minor` | `invoice_id` |

**Design decisions:**

- **Money is integers.** Prices are in minor units (paise). Overage rates are in **micros**
  (millionths of a rupee), so sub-paisa rates such as ₹0.0025 per API call are exact. Rounding is
  half-up, **once per line item**.
- **Plan segments with snapshotted pricing** (`subscription_plan_periods`) are how mid-cycle plan
  changes work: an upgrade closes the open segment and opens a new one. Pricing is copied in, so a
  merchant editing a plan never changes an in-flight cycle's bill, and billing never needs the plan cache.
- **Status and cycle columns are strings backed by PHP enums**, not DB `ENUM`s, so adding a value
  needs no `ALTER` on a large table.
- **`usage_events` has no foreign keys and no `updated_at`.** FKs cost a lookup per insert and
  are not allowed on partitioned MySQL tables. Integrity is enforced once at the API boundary,
  and events are immutable.
- **`daily_usage` is a deliberate denormalisation**: about 30 rows per customer per month instead of
  thousands of raw events. Billing and the dashboard read only this table.
- **`DateOnly` cast.** Laravel's `date` cast writes `Y-m-d 00:00:00`. MySQL's `DATE` truncates that,
  but SQLite keeps it, which silently breaks `ends_on >= '2026-10-31'` on boundary days. Every
  date-only column stores a plain `Y-m-d`.

---

## 5. Billing rules

Cycles are **calendar-anchored**: monthly plans bill the calendar month, yearly plans the calendar year.

For each plan segment overlapping the cycle, covering **D** days of a cycle that is **C** days long
(the full calendar month or year):

```
base       = round(base_price × D / C)
allowance  = round(included_units × D / C)
used       = Σ daily usage on the segment's days
overage    = max(0, used − allowance) × that segment's overage rate
invoice    = Σ over segments (base + overage)
```

**Worked example (upgrade).** Starter (₹500, 5,000 units, ₹1.00/unit) on 1–15 Oct, then Pro
(₹1,000, 10,000 units, ₹0.50/unit) from 16 Oct. Usage: 4,000 before the change, 9,000 after.

| Line | Days | Calculation | Amount |
|---|---|---|---|
| Starter base | 15/31 | 50,000 × 15/31 | ₹241.94 |
| Starter overage | 15/31 | (4,000 − 2,419) × ₹1.00 | ₹1,581.00 |
| Pro base | 16/31 | 100,000 × 16/31 | ₹516.13 |
| Pro overage | 16/31 | (9,000 − 5,161) × ₹0.50 | ₹1,919.50 |
| **Total** | | | **₹4,258.57** |

This exact case is a unit test (`BillingCalculatorTest::test_upgrade_mid_cycle_bills_each_segment_at_its_own_plan`)
and an end-to-end test (`InvoiceGenerationTest`).

**Timing.** An invoice is generated only once the **late-arrival window** (3 days) after the cycle
has closed, so October is invoiced on 4 November. Usage dated 31 October may arrive until 3 November
and must be counted. `GenerateInvoiceAction` re-aggregates the customer's cycle from raw events
before pricing, so an invoice never depends on the hourly rollup having caught up.

---

## 6. Idempotency, queues and chunking

**Usage ingestion.** Idempotency is enforced by the database, not by a check-then-insert in PHP
(which races when two retries arrive at once). The unique index `(merchant_id, idempotency_key)`
lets exactly one `INSERT` win. The loser catches `UniqueConstraintViolationException`, compares the
payload, and returns the stored event (`200`) or a `409` if the payload differs. The happy path is
**one INSERT**, with no locks, transactions or Redis. Keys are scoped per merchant, so tenants cannot collide.

**Aggregation.** `UsageAggregator` writes **absolute totals** (`SUM` recomputed from raw rows) via
upsert, never `+= delta`. Re-running a day, overlapping schedules, retries and late events all
converge on the correct number. `aggregateDate()` pages through the day's customers with **keyset
pagination** (`customer_id > last`, `LIMIT 1000`) over the `(usage_date, customer_id)` index, so
each chunk is a bounded range scan however deep into the day it is, and memory stays flat.

**Invoicing.** `GenerateDueInvoices` pages due subscriptions with `chunkById(500)`, selecting only ids,
and fans out one `BillSubscription` job each. The work parallelises across workers, and a failure retries
only one subscription. Inside, `GenerateInvoiceAction`:
1. locks the subscription row (`SELECT … FOR UPDATE`, the same lock a plan change takes),
2. returns the existing invoice if this cycle is already invoiced (crash-after-insert recovery), and
   the unique `(subscription_id, period_start)` index backs this up,
3. otherwise prices it, stores the invoice and line items, and advances to the next cycle.

`BillSubscription` loops while the subscription is due, so after an outage it **catches up missed
cycles in order**, one invoice each. All jobs are `ShouldBeUnique` with `tries`/`backoff`.

---

## 7. Caching and invalidation

| Cache | Key | Invalidated by | TTL |
|---|---|---|---|
| **Plan catalogue** (`PlanCatalog`) | `plan-catalog:merchant:{id}`, one entry per merchant | `PlanObserver` on create/update/delete, **after commit** | 1h (backstop) |
| **Dashboard** (`DashboardCache`) | `dashboard:merchant:{id}:{date}:{version}` | bumping the merchant's version token | 5 min (backstop) |

**Plan catalogue.** One entry per merchant rather than per plan: catalogues are small, readers want
several plans at once, and invalidation is a single `forget`, with no cache tags needed (the database and
file stores don't support tags). The observer implements `ShouldHandleEventsAfterCommit`. Forgetting
inside an open transaction would let a concurrent reader re-cache the old row before the write is
visible; there is a test for this. The TTL only covers writes that bypass Eloquent (raw SQL fixes).
Cached values are plain arrays, never serialised Eloquent models. **Billing never reads this cache**
(it prices from the segment snapshot), so a stale catalogue cannot produce a wrong invoice.

**Dashboard.** The key embeds a per-merchant version token. Invalidation writes a new token, so
every cached entry for that merchant becomes unreachable at once and expires on its TTL. There is no
key enumeration and no tags. The version is bumped by everything that changes the dashboard's inputs:
- the usage rollup (for each merchant it wrote rows for),
- a subscription starting,
- a plan change,
- a plan edit (names are shown).

The cache store is configurable (`CACHE_STORE`). Locally it is `database`. In production it
should be **Redis**, which also serves the rate limiter and unique-job locks, because those are
per-request writes that should not land on the primary database.

---

## 8. Scaling to 50L+ usage events

**What 5M rows (50 lakh) costs.** A rough estimate: about 100 bytes per row (8-byte ids, ~40-byte key, date,
timestamp, InnoDB overhead) is about 500 MB of data, plus about 500 MB for the three secondary indexes:
**about 1 GB in total**. One MySQL instance handles that comfortably. The real question is
**growth** (5M a month is 60M a year), so the design keeps every hot query bounded by date rather than by
table size.

**Every query is index-bounded:**

| Query | Index used | Cost grows with |
|---|---|---|
| Usage insert + idempotency check | unique `(merchant_id, idempotency_key)` | O(log n), one B-tree probe |
| Daily rollup: customers on day X | `(usage_date, customer_id)` range + keyset | events **that day** |
| Invoice re-aggregation: one customer, one cycle | `(customer_id, usage_date)` range | that customer's events in the cycle |
| Dashboard (all three panels) | `daily_usage` covering index | customers × days in 2 months (no raw events) |
| Billing scan | `subscriptions (status, current_period_end)` | due subscriptions |

No request path scans `usage_events` without a leading equality or date range, and the dashboard
never touches it.

**Write path.** Each event is one INSERT that maintains the clustered PK plus 3 secondary indexes.
Ids are auto-increment, so inserts append to the right edge of the clustered index (no page
splits). InnoDB on SSD handles several thousand single-row inserts a second. The next steps, in order:
1. A **batch endpoint** (`POST /usage/batch`, up to 500 events per call, one multi-row `INSERT IGNORE`
   plus per-row status). Most metering clients buffer anyway.
2. **Buffer through a queue** (Redis Streams, SQS or Kafka) and bulk-insert from consumers, so the API acks after
   an O(1) enqueue and the database sees large batches.

**Partitioning (when the table reaches roughly tens of millions of rows).** `RANGE COLUMNS(usage_date)`
by month. Old months become `DROP PARTITION` / archive operations instead of `DELETE`s, and date-bounded
queries prune to one or two partitions. The indexes were ordered with this in mind (`usage_date` leads
the rollup index). MySQL requires the partition key in every unique key, so the change is:

```sql
ALTER TABLE usage_events
  DROP PRIMARY KEY, ADD PRIMARY KEY (id, usage_date),
  DROP INDEX usage_events_merchant_id_idempotency_key_unique,
  ADD UNIQUE KEY usage_events_idem (merchant_id, idempotency_key, usage_date);

ALTER TABLE usage_events PARTITION BY RANGE COLUMNS (usage_date) (
  PARTITION p2026_10 VALUES LESS THAN ('2026-11-01'),
  PARTITION p2026_11 VALUES LESS THAN ('2026-12-01'),
  PARTITION pmax     VALUES LESS THAN (MAXVALUE)
);
```

**The catch:** idempotency is then only unique **per date**. A retry that changes `usage_date` would no
longer be caught (today it gets a `409`). The fix is to move the idempotency guard out of the
partitioned table: a small unpartitioned `idempotency_keys` table (pruned after the late window) or
Redis `SET NX` with a TTL longer than the late window. I didn't partition now because at 5M rows
it adds operational cost for no measurable gain, and the SQLite test database can't express it.

**Further steps, if needed:**
- Archive raw events older than the dispute window to cold storage. `daily_usage` and invoices
  remain the system of record.
- Move dashboard reads to a read replica.
- Use a monthly rollup if dashboards ever need multi-month history.

---

## 9. Assumptions

The brief invites judgement calls. These are the ones I made:

1. **Calendar-anchored cycles.** A mid-month signup gets a short first cycle (signup → month end),
   which is the proration case. Anniversary billing would need an anchor-day column.
2. **Proration is by day**, with the cycle length as denominator (28–31 for months, 365/366 for years).
3. **The included allowance is prorated too.** A half-month subscriber gets half the units, not the full
   allowance at half price.
4. **Plan segments are billed independently.** After a plan change, usage is attributed to the segment
   its day falls in, priced at that segment's rate against that segment's prorated allowance.
   **Allowances are not pooled** across segments (unused Starter units don't offset Pro overage).
5. **Plan changes take effect at the start of a day** (usage is metered per day). The change day's usage
   belongs to the new plan. A change on the segment's first day re-prices the segment in place.
6. **No backdated plan changes** (that would re-price already-accepted usage), and **no cross-cycle
   changes** (monthly ↔ yearly), which would also need the cycle boundaries moved.
7. **Late events are accepted for 3 days** (`USAGE_LATE_ARRIVAL_DAYS`). Invoicing waits for the window to
   close, so invoices are final. Usage older than that is rejected with `422`.
8. **Usage requires an active subscription.** Usage that could never be invoiced is rejected at the
   door rather than stored as orphan rows. Usage on days no segment covers is not billed.
9. **One active subscription per customer**, and **one currency per merchant** (INR in the demo).
10. **Dates are UTC.** "Today" for usage defaults and dashboards is the UTC date.
11. **Churn risk** compares month-to-date with the **same span of last month** (1st to the same day, clipped
    to the shorter month). Comparing a partial month with a full one would flag everyone early in the
    month. Customers with no usage in the baseline span are not flagged (there is nothing to drop from).
    Exactly 50% is not "more than 50%".
12. **Projected overage** extrapolates each subscription's average daily usage so far, through today, to
    the end of the cycle containing today. Today's partial usage makes early-day projections slightly
    conservative.
13. **Idempotency keys are per merchant** and permanent (not expiring) while events live in a single table.
14. **The wireframe's "Active Plan" card** shows the **most subscribed plan** (with its share of
    subscriptions). A merchant offers several plans, so "the" active plan is ambiguous at merchant level.
    The card's "Current Cycle Usage" totals usage against the sum of prorated allowances across
    active subscriptions.
15. **Merchants authenticate with API keys.** There is no merchant/admin UI or user auth in scope. Plans and
    subscriptions are managed through actions (used by the seeder and tests). HTTP endpoints for them are listed under §11.

---

## 10. Trade-offs made under time pressure

| Decision | Why | Cost |
|---|---|---|
| Database queue + cache locally | Zero extra infrastructure to review | Rate limiter writes to the DB per request; production should use Redis |
| Per-row `INSERT` for usage, no batch endpoint | Simplest correct idempotency story | Throughput ceiling is single-row inserts (see §8) |
| Indexes designed for partitioning, but no partitions | 5M rows doesn't need it; SQLite tests can't express it | Needs a migration and an idempotency-guard move when growth demands it |
| No HTTP endpoints for plans/subscriptions CRUD | Brief scoped usage, billing and dashboard | Managed via actions, the seeder and tests |
| Invoice status is only `issued` | No payments in scope | `paid`/`void` exist in the enum but nothing transitions to them |
| Dashboard projection is a linear extrapolation | Explainable and testable | Ignores weekly seasonality |
| Developed on MariaDB 10.4 (XAMPP) | Available locally; MySQL-compatible for everything used | Not run on MySQL 8 itself; nothing used is MariaDB-specific |

---

## 11. What I'd do with more time

- **Batch ingestion endpoint** plus queue-buffered writes (§8).
- **Load test** at 5M+ rows: seed via `INSERT … SELECT` doubling, capture `EXPLAIN` plans and p95 latencies
  for the rollup, invoicing and dashboard queries.
- **HTTP API** for plans, subscriptions and plan changes, with policies.
- **Cancellation** with a final prorated invoice; **anniversary billing**; cross-cycle plan changes.
- **Invoice lifecycle:** finalisation, payment status, credit notes for disputes, PDF rendering, webhooks.
- **Multi-currency** and tax lines.
- **Observability:** metrics on ingest rate, rollup lag and invoices issued; alert when the rollup
  falls behind the late window.
- **Laravel Horizon** on Redis for queue visibility; separate queues for ingestion, rollup and billing.
- **Dashboard UI:** drill-down to a customer's invoices and segments, and date-range selection for the trend.

---

## 12. Testing

```bash
php artisan test
```

91 tests run against in-memory SQLite (`phpunit.xml`), in about 30s.

| Suite | Covers |
|---|---|
| `Unit/BillingCalculatorTest` | Full cycle, at/over allowance, mid-cycle start, leap & non-leap February, yearly plans, sub-paisa rounding, upgrade, downgrade (old rate kept), change-day attribution, no allowance pooling, segment clipping |
| `Feature/Api/RecordUsageTest` | 201/200/409, header or body key, per-merchant key scope, cross-tenant rejection, inactive subscription, auth, validation matrix, late window, per-customer and per-merchant rate limits |
| `Feature/Billing/UsageAggregationTest` | Per-customer daily sums, idempotent re-runs, late events, keyset chunking (incl. exact multiples), job and command |
| `Feature/Billing/InvoiceGenerationTest` | End-to-end proration, upgrade/downgrade invoices, late-window timing, no double invoicing, catch-up of missed cycles, plan-price edits not affecting in-flight cycles, plan-change guards |
| `Feature/Caching/PlanCatalogTest` | Cache hits (zero queries), invalidation on create/update/delete, per-merchant scope, **after-commit** invalidation, `/api/plans` |
| `Feature/Api/MerchantDashboardTest` | Top 5 with % of prorated allowance, summary cards, 30-day trend with gap filling, projections (incl. across a plan change), churn thresholds and clipped spans, access control, `/api/merchant`, cache invalidation by rollup and plan rename |
| `Feature/DashboardPageTest` | `/` redirect, all wireframe panels render, demo-key shortcut only when local |
| `Feature/DemoSeederTest` | The demo seed on a mid-month day, the 1st, a leap 29 Feb and 31 Dec, with a fresh rollup timestamp |

The expected amounts in the billing tests are worked out by hand in comments next to each assertion.

**Bugs the tests and manual runs caught during development**, kept as regression tests:
- the `date` cast storing midnight timestamps, which broke SQLite boundary comparisons;
- a plan created without `is_active` reading as inactive in memory;
- the rate limiter running before authentication, so it could never key by merchant;
- the seeder resetting the caller's clock, and stamping the rollup with a back-dated clock.

---

## 13. AI-assisted development

This project was built with **Claude Code** (Anthropic), used as a pair programmer: I set direction and
made the decisions, and the AI drafted code, tests and documentation, which were reviewed and run against both
SQLite and MariaDB at each step. Screenshots of the actual prompts are in [`/prompts`](prompts/).
