# Offers API

A REST API that asynchronously imports accommodation offers from suppliers, returns the
cheapest currently valid offer for each property, and lets a client book it safely.

## Stack

| | |
|---|---|
| PHP | 8.3 |
| Laravel | 12 |
| MySQL | 8.4 |
| Queue | Redis |
| Tests | PHPUnit |
| Environment | Docker (Laravel Sail) |

---

## Getting started

The only prerequisite is Docker.

```bash
cp .env.example .env
```

```bash
composer install
```

```bash
./vendor/bin/sail up -d
```

```bash
./vendor/bin/sail artisan key:generate
```

Migrations and the supplier seeder (`supplier-a`, `supplier-b`):

```bash
./vendor/bin/sail artisan migrate --seed
```

The API is available at `http://localhost:8000`.

### Containers

| service | what it is |
|---|---|
| `laravel.test` | PHP 8.3 + built-in server, port `8000` |
| `queue` | dedicated worker: `php artisan queue:work redis --tries=3` |
| `mysql` | MySQL 8.4, volume `sail-mysql`, exposed on `3307` |
| `redis` | Redis, volume `sail-redis`, exposed on `6380` |

---

## Commands

Tests:

```bash
./vendor/bin/sail artisan test
```

Queue worker logs:

```bash
./vendor/bin/sail logs -f queue
```

Stop the stack (`-v` also wipes the MySQL and Redis data):

```bash
./vendor/bin/sail down
```

Tests run against a separate `testing` database that the MySQL container creates on first
initialization.

---

## API

### `POST /api/imports`

Accepts an import, queues it and immediately returns `202`.

At most **5000 offers** per import. Larger catalogues are split across several imports, each
with its own `external_import_id` and a later `sent_at`.

```json
{
  "supplier": "supplier-a",
  "external_import_id": "import-2026-09-01-001",
  "sent_at": "2026-09-01T10:00:00Z",
  "offers": [
    {
      "external_id": "offer-a-10001",
      "property": { "code": "BCN-0001", "name": "Apartment near Sagrada Familia", "city": "Barcelona" },
      "check_in": "2026-10-10",
      "check_out": "2026-10-15",
      "max_guests": 4,
      "price": 72500,
      "currency": "EUR",
      "available_units": 2,
      "expires_at": "2026-09-10T23:59:59Z"
    }
  ]
}
```

```json
{ "data": { "id": 15, "status": "pending" } }
```

### `GET /api/imports/{import}`

```json
{
  "data": {
    "id": 15,
    "supplier": "supplier-a",
    "external_import_id": "import-2026-09-01-001",
    "sent_at": "2026-09-01T10:00:00Z",
    "status": "completed",
    "total_offers": 20,
    "processed_offers": 20,
    "error": null,
    "skipped": [],
    "created_at": "2026-09-01T10:00:02Z",
    "completed_at": "2026-09-01T10:00:04Z"
  }
}
```

Statuses: `pending`, `processing`, `completed`, `failed`.

`error` is a summary, never a raw failure. An import that could not apply every offer names
them in `skipped` — at most fifty, with the count in `error`:

```json
{
  "error": "Skipped 2 of 20 offers.",
  "skipped": [
    { "external_id": "offer-a-3", "code": "invalid_data" },
    { "external_id": "offer-a-7", "code": "constraint_violation" }
  ]
}
```

Codes: `invalid_data` (the offer does not fit the catalogue), `constraint_violation` (it
contradicts something already there). What actually threw goes to the log, keyed by import and
`external_id`; it is never returned.

### `GET /api/properties`

```
GET /api/properties?city=Barcelona&check_in=2026-10-10&check_out=2026-10-15&guests=2&page=1
```

```json
{
  "data": [
    {
      "code": "BCN-0001",
      "name": "Apartment near Sagrada Familia",
      "city": "Barcelona",
      "best_offer": {
        "id": 125,
        "supplier": "supplier-a",
        "price": 72500,
        "currency": "EUR",
        "available_units": 2,
        "expires_at": "2026-09-10T23:59:59Z"
      }
    }
  ],
  "links": { "first": "...", "last": "...", "prev": null, "next": "..." },
  "meta": { "current_page": 1, "per_page": 15, "total": 42, "...": "..." }
}
```

`check_in`, `check_out` and `guests` are required, `city` is not.

### `POST /api/offers/{offer}/reservations`

```json
{
  "client_reference": "web-order-9f782b1c",
  "customer_name": "John Smith",
  "customer_email": "john@example.com"
}
```

### Response codes

| Endpoint | Code | Condition |
|---|---|---|
| `POST /api/imports` | `202` | Created and queued, **or** the import already exists |
| | `409` | `sent_at` is not newer than the supplier's latest import |
| | `422` | Invalid structure, unknown supplier, or more than 5000 offers |
| `GET /api/imports/{import}` | `200` | Current state |
| | `404` | Not found |
| `GET /api/properties` | `200` | A page of results |
| | `422` | Invalid search parameters |
| `POST /api/offers/{offer}/reservations` | `201` | Created, one unit taken |
| | `200` | Repeated `client_reference` — the existing reservation, inventory untouched |
| | `409` | No units left, or the offer expired |
| | `422` | Invalid body |
| | `404` | Offer not found |

---

## How an import is processed

The request never hands the offers to the queue. `RegisterImport` writes the `imports` row and
parks every offer in **`import_offers`** in the same transaction, then dispatches
`ProcessImport` carrying nothing but the import id.

That keeps the queue message a few bytes wide no matter how large the import, so a retry costs
nothing to re-serialize, and it gives each offer somewhere to record its own outcome
(`pending` → `applied` / `skipped`, with an error code) instead of everything landing in one
free-text field on the import.

`ProcessImport` walks the staged offers with `chunkById`, filtering on `status = pending`. A
job that dies half-way and is retried therefore resumes rather than restarting: offers already
applied are no longer pending and are skipped over. `processed_offers` and the skipped summary
are read back from the staged rows at the end, not counted in memory, so a resumed run still
reports the whole import rather than only the part it saw.

Staged offers are the import's audit trail, and they are pruned along with the import itself —
see [Retention](#retention).

### When an offer fails

A staged offer ends in one of three states, and the difference decides whether the import
carries on:

| | Meaning | What happens |
|---|---|---|
| `applied` | Written to the catalogue | Done |
| `skipped` | The offer itself is at fault | Recorded with a code; the import carries on and completes |
| `pending` | Nothing about the offer explains the failure | The job fails, the queue retries, the offer is tried again |

Only a failure that describes the row — SQLSTATE class `22` (bad value) or `23` (constraint) —
counts as the offer's fault. Everything else is treated as a failure of the run: a lost
connection, a deadlock, a bug. Those are re-thrown rather than filed against the offer, so a
retry can pick the row up and, once the attempts run out, the import ends as `failed` instead
of reporting `completed` over silently dropped offers.

The default matters more than the list. Anything unrecognised fails loudly; only what is
explicitly known to be the offer's own problem is skipped.

---

## Retention

Imports older than `IMPORT_RETENTION_DAYS` (default **90**) are removed by the scheduled
`model:prune` command, taking their staged offers with them via `ON DELETE CASCADE`.

Offers still in the catalogue survive; they only lose `last_import_id`, which is provenance
rather than data. Run the scheduler for this to happen:

```bash
./vendor/bin/sail artisan schedule:work
```

---

## Import idempotency

Two independent levels, and both rest on **unique indexes in the database** rather than on a
"`SELECT` first, then `INSERT`" check. There is always a window between the read and the write
for a second request to slip through, so the database must have the final say.

**Import level** — `UNIQUE (supplier_id, external_import_id)`. A resend returns the same `id`
with the **current** status and does **not** queue the job a second time. A concurrent duplicate
surfaces as a `UniqueConstraintViolationException` and resolves to the row that won the race.

**Offer level** — `UNIQUE (supplier_id, external_id)`. Composite, so different suppliers may
carry the same `external_id`. An offer coming from another import is updated, not duplicated.

---

## Interpretations of the specification

Places where the specification allowed more than one reading.

**Dates match exactly.** An offer is modelled as an indivisible block with one total price. The
"covering range" reading would make the price comparison meaningless: a 10-night offer would
satisfy a 5-night search, but the guest would pay the full 10-night price and compete for the
"cheapest" title against an honest 5-night offer. A per-night model would allow range search,
but goes beyond the data structure given.

**Partial success.** Errors are split by level:

- an **offer-level** error (one record did not go through) — the offer is skipped and recorded
  in `error` ("Skipped 1 of 2 offers…"), the import finishes as `completed` with
  `processed_offers < total_offers`;
- an **import-level** error (the supplier vanished, the database is unreachable, retries are
  exhausted) — `failed`.

That keeps both fields meaningful at the same time. If every offer failed, the import is still
`completed` with `processed_offers = 0` — consistency beats a special case.

**One currency.** No conversion, prices compare numerically. In production this would require a
separate exchange-rate layer.

**`client_reference` is unique within an offer.** The `web-order-` prefix says the reference
belongs to an order rather than to a customer: otherwise the same customer could not book a
second offer.

**No authentication** — deliberately, per the scope of the task: "we are interested in the
database structure, working with Laravel, SQL queries, queues, transactions and automated
tests". The default scaffolding that came with it — the `User` model, `config/auth.php`,
`config/session.php` and the `users`/`sessions` tables — is removed rather than left dormant.

**No frontend.** There is no `routes/web.php`, no Blade views and no asset pipeline: the only
entry points are the API routes and `/up`. `GET /` returns `404`.

**Results are ordered by the best offer's price**, because finding the cheapest accommodation is
the whole point of the query.

**Job retries are safe.** `tries = 3` together with `ShouldBeUnique`: a second pass over the same
payload rewrites nothing, because the upsert is keyed and conditional. An import left hanging in
`processing` by a crashed worker is finished by a retry.

---

## Known limitation: `available_units`

The supplier is treated as the source of truth about availability, and
every import resets it to what the supplier declares. This matches real OTA architecture, where
the supplier's inventory is authoritative — but there, bookings are **pushed back to the
supplier**, and its next feed already accounts for them. This task has no such channel, so it is
the push that closes the gap, not extra logic on our side.

The alternative, deliberately not implemented: split `available_units` (from the supplier) and
`reserved_units` (ours), and compute availability as the difference through a generated stored
column.

---

## Tests

```bash
./vendor/bin/sail artisan test
```

81 tests. They cover what the task is actually about:

- a repeated import neither duplicates the record nor queues the job twice (`Queue::fake`);
- an import with an older `sent_at` is rejected with `409`;
- an older import does not overwrite a newer one's data when processed out of order;
- a faulty offer is skipped and the import still finishes as `completed`;
- the outcome of every offer is recorded against its staged row;
- a failure that is not the offer's fault leaves it pending and stops the import;
- the status endpoint returns codes for skipped offers, never the underlying failure;
- a job rerun after a partial run resumes instead of reapplying, and still reports the whole import;
- an import larger than one chunk is processed in full, and one over the cap is rejected;
- pruning removes an expired import and its staged offers but leaves the catalogue standing;
- the search returns the cheapest offer and ignores expired and sold out ones;
- a property stays in the results when its cheapest offer is filtered out;
- pagination is stable when prices tie;
- the last unit is booked once, the second attempt gets `409`;
- a repeated `client_reference` gets `200` without a second decrement;
- the retry that sold the offer out still receives its reservation.

---

## Structure

```
app/
├── Actions/          # business logic: RegisterImport, SearchProperties, CreateReservation
├── Enums/            # ImportStatus, ImportOfferStatus, ImportOfferError
├── Exceptions/       # StaleImportException, OfferNotBookableException
├── Http/
│   ├── Controllers/Api/
│   ├── Requests/     # validation
│   └── Resources/    # response serialisation
├── Jobs/             # ProcessImport
└── Models/
```
