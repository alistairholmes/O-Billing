# O-Billing Deployment Runbook — Zaka RDC

Zaka Rural District Council's instance. Same one-instance-per-council model as
Binga: its **own** Railway project, **own** Postgres, **own** subdomain, **own**
Sage worker. Nothing is shared with Binga or Gokwe South except the code.

Read [`deployment-binga.md`](deployment-binga.md) for the architecture and the
reasoning behind each service — this file only carries Zaka's specifics.

| | |
|---|---|
| Council | Zaka Rural District Council (Masvingo Province) |
| Branch | `zaka` (fast-forward mirror of `main`) |
| Railway project | `chic-unity` — `132dd488-6c72-4860-86da-9be498656bd6` (env `9f12c910-f445-49a8-8d76-52692d8aaa72`) |
| Railway account | the `alistairholmes` **GitHub** login — **not** `olimemdevelopers@gmail.com` |
| Domain | `billing.zakardc.co.zw` |
| Municipality code | `ZRDC` |
| Sage | **not yet provisioned**, and on a **different VPS** to Binga's — see [Part C](#part-c--sage-pending) |

---

## Part A — Cloud app on Railway

Four services, all from the `zaka` branch of
`Olimem-Enterprise-Solutions/O-Billing`. **A1–A5 are provisioned and live** as of
2026-08-27; A6 is deliberately not built yet.

| Service | Id | Role |
|---|---|---|
| `Postgres` | `6f248b29-72f5-4303-a6ee-f3010f7a2c28` | database |
| `obilling` | `726efc9f-96e0-45e0-9a04-83b04e9c9300` | web (A2) |
| `obilling-worker` | `a45bdd4a-d5e0-49bb-a85e-d52fb95f6b35` | default queue (A4) |
| `obilling-scheduler` | `4ce97b1b-1f80-4c5b-aac8-b4ca98905976` | `schedule:work` (A5) |

> **The `railway` CLI cannot do all of this.** Its interactive-login token is
> rejected (`Unauthorized`) for creating repo-linked services, setting a
> service's deploy branch, and adding domains — though `whoami`, `list`,
> `add --database`, `variables` and `ssh` all work fine. For the rest, call the
> GraphQL API directly with the **`accessToken`** (not `token`) from
> `~/.railway/config.json`:
>
> ```
> TOKEN=$(python3 -c "import json;print(json.load(open('$HOME/.railway/config.json'))['user']['accessToken'])")
> curl -sS -X POST https://backboard.railway.com/graphql/v2 \
>   -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" -d '{"query":"..."}'
> ```
>
> The useful mutations are `serviceCreate`, `serviceConnect` (repo **and**
> branch), `serviceInstanceUpdate` (`startCommand`, `region`),
> `customDomainCreate` and `serviceDomainCreate`. Introspect an input type with
> `{__type(name:"XInput"){inputFields{name}}}` — the shapes are not documented.
>
> Two traps worth knowing. `serviceCreate` accepts a `branch` argument but does
> **not** honour it — the service's first deploy still comes from the repo's
> default branch, so always follow up with `serviceConnect` and then verify via
> `service{repoTriggers{edges{node{branch}}}}`. And the branch does *not* live on
> `ServiceInstanceUpdateInput`, whose `source` field only carries `image`/`repo`;
> reaching for it there returns a bare "Problem processing request".

### A1. Postgres

Add a **PostgreSQL** database to the project (`railway add --database postgres`
works). Everything else references it as `${{Postgres.DATABASE_URL}}`; the
external `DATABASE_PUBLIC_URL` is only needed if something outside Railway has
to connect.

### A2. Web service (`obilling`)

Deploy the repo with Railway's **default builder (Railpack)** — no root
`Dockerfile`. The web image deliberately has **no SQL Server driver**; the cloud
app never talks to Sage.

| Variable | Value |
|---|---|
| `APP_NAME` | `O-Billing — Zaka` |
| `APP_ENV` | `production` |
| `APP_KEY` | `base64:…` — generate once with `php artisan key:generate --show` |
| `APP_DEBUG` | `false` |
| `APP_URL` | `https://billing.zakardc.co.zw` |
| `APP_BRAND_NAME` | `Zaka Rural District Council` |
| `APP_BRAND_LOGO` | `/zaka-logo.png` |
| `APP_BRAND_LOGO_HEIGHT` | `2.75rem` |
| `DB_CONNECTION` | `pgsql` |
| `DB_URL` | `${{Postgres.DATABASE_URL}}` |
| `DB_SSLMODE` | `require` |
| `SESSION_DRIVER` / `QUEUE_CONNECTION` / `CACHE_STORE` | `database` |
| `MAIL_*` | SMTP, for password resets and notifications |

`APP_KEY` must be **identical on all four services** — that is how the workers
decrypt the same queue payloads.

> Copying a whole env from Binga is the standard way this gets wrong: check
> `APP_NAME`, `APP_URL` and the three `APP_BRAND_*` vars by eye before go-live.
> Gokwe South shipped with Binga's branding for exactly this reason.

**Railpack runs `php artisan migrate --force` itself on every deploy** — its
Laravel provider detects the app and does it, so the schema was already in place
before anyone ran a command by hand. Don't add a migrate step expecting it to be
the first one; `migrate:status` is the honest way to check.

The one thing that *is* manual is the first admin, since the first login has to
come from somewhere:

```
railway ssh --service obilling 'php artisan user:provision "Full Name" user@example.com --admin'
```

It prints a generated password once. Further users come from the panel's
**Users** page.

**The municipality has to exist before anyone can log in.** Tenant registration
was removed from the panel in `c9cf004` ("councils are created by the Sage
import"), so on a council with no Sage there is no screen that creates the first
one — and a user with no tenant gets a **404 immediately after logging in**,
because `/admin` redirects to `/admin/{tenantId}` and there is no tenant to
redirect to. Zaka hit exactly this.

For a Sage-less council, create it directly and attach the users:

```php
$municipality = App\Models\Municipality::firstOrCreate(['code' => 'ZRDC'], [
    'name' => 'Zaka Rural District Council',
    'base_currency' => 'USD',
    'supported_currencies' => ['USD', 'ZWG'],
    'tax_rate' => 0.15,          // stored as a fraction, not a percentage
    'tax_label' => 'VAT',
    'active' => true,
]);

foreach (App\Models\User::all() as $user) {
    $user->municipalities()->syncWithoutDetaching([$municipality->id]);
}

// Baseline area types + services, as RegisterMunicipality used to seed.
app(App\Support\Tenancy\CurrentMunicipality::class)->runFor($municipality->id, function () use ($municipality) {
    if (App\Models\AreaType::count() === 0) {
        App\Support\DefaultSetup::seed($municipality);
    }
});
```

Leave `setup_completed_at` null so the Setup Wizard still runs. Run it through
`railway ssh --service obilling`, writing the script to a file and invoking
`php artisan tinker --execute='require "/tmp/bootstrap.php";'` — bare
`php artisan tinker <file>` executes the file but then drops into the REPL and
hangs a non-interactive session.

### A3. Domain

`billing.zakardc.co.zw` is registered on the web service. Create this record in
the `zakardc.co.zw` zone — TLS follows automatically once it resolves:

| Type | Host | Value |
|---|---|---|
| CNAME | `billing` | `9silovqy.up.railway.app` |

Railway also issued `obilling-production.up.railway.app`, which works right now
and is handy for testing ahead of DNS.

### A4. Default-queue worker (`obilling-worker`)

Second service, same source and same variables as A2, start command:

```
php artisan queue:work --queue=default --tries=3 --timeout=120
```

**Never let this service listen on `sage`.**

### A5. Scheduler (`obilling-scheduler`)

Third service, same source and variables:

```
php artisan schedule:work
```

Fires the hourly `billing:run-scheduled` command behind **Billing → Billing
schedules**. Needs no Sage connection.

### A6. Sage worker — set the region to EU

Fourth service, built from **`docker/worker.Dockerfile`** (this is the only image
with `pdo_sqlsrv`), draining only the `sage` queue:

```
php artisan queue:work --queue=sage --tries=1 --timeout=0
```

Extra env on this service only: the `SAGE_DB_*` set, plus
`DB_QUEUE_RETRY_AFTER=14400` and `SAGE_JOB_MEMORY_LIMIT=2048M`.

> **Put this service in the Railway region closest to Zaka's Sage VPS** (Part C).
> Posting is thousands of sequential round-trips, so worker↔Sage distance
> dominates everything else. Measured on Binga, whose Sage VPS is in Germany:
> ~29 ms/round-trip from EU-West vs ~184 ms from US-East — 6×, or ~15 min vs
> ~2.5 h on a 3,500-document run.
>
> That measurement compares two **European** endpoints. It is not evidence that
> a distant Sage can be made fast by picking a region: no Railway region is near
> Zimbabwe, so if Zaka's Sage ever sat on the council LAN, every region would be
> ~180 ms away and a full run would take hours. Co-location is the lever, not
> the region setting on its own.

`DB_QUEUE_RETRY_AFTER=14400` is required on **any** service that runs `sage`
jobs — the default 90 s makes the queue declare a long posting job dead
mid-flight and, with `--tries=1`, dump it into `failed_jobs` while nothing is
actually wrong.

---

## Part B — Branding

The crest is committed at `public/zaka-logo.png` (124×132, transparent
background, trimmed) and served straight from `public/`. Branding is entirely
env-driven — `config/app.php` reads `APP_BRAND_NAME` / `APP_BRAND_LOGO` /
`APP_BRAND_LOGO_HEIGHT` — so there is no per-council code and the `zaka` branch
stays a clean mirror of `main`.

`2.75rem` rather than Binga's default `2.5rem`: Zaka's mark is a near-square
crest, not a wide wordmark, so it needs a little more height to read.

---

## Part C — Sage (pending)

Zaka's Sage currently runs on a **council-owned** machine, `zaka-billing-server`,
now on the tailnet at **`100.78.84.38`** (Windows, SQL Server **Express**, clock
in UTC, internet via **Starlink** — its tailnet peer endpoint is a
`2605:59c1::/32` SpaceX address). That machine is out of bounds for our code:
council IT administers it, so an on-site worker would put O-Billing's source on
hardware we don't control. Decided 2026-08-28 to follow **Binga's model
instead — host Zaka's Sage on an Olimem VPS** and run the Sage worker beside it.

Tailnet access does not change that decision; it makes executing it practical.
Schema discovery, the posting map, and the backup itself can now all be done
over the tailnet instead of through AnyDesk.

**The companies on that server** (2026-08-29):

| Database | Clients | Invoices | Last invoice | Size |
|---|---|---|---|---|
| `ZAKA Rural District Council` | 21,734 | 6,154 | 2025-03-21 | 592 MB |
| `ZAKA Rural District Council (ZiG)` | 21,476 | 0 | — | 912 MB |
| `Copy of ZAKA RURAL DISTRICT COUNCIL` | 20,780 | 22 | 2024-02-27 | 336 MB |

Two things to settle from this. Zaka has not raised an invoice in Sage since
**March 2025**, so confirm what the council is actually running on today. And
there is both a USD company and a **ZiG** one with no invoices at all — which of
the two O-Billing treats as the source of truth is an open question, not a
detail.

**Zaka is roughly twice Binga's size — 21,734 ratepayers against 10,371 — which
raises the cost of getting co-location wrong.** Scaling Binga's measured figures
(3,500 documents in ~15 min at 29 ms, i.e. ~9 round-trips per document), a Zaka
run of ~7,000 documents lands near **30 min at 29 ms** but **over 2 hours at
125 ms**. The worker has to sit next to Sage; no transport choice substitutes
for that.

This is a hosting decision, not a networking one. Tunnelling to the council LAN
was considered and rejected: no cloud region is near Masvingo, so a remote
worker sits ~180 ms from Sage whatever the transport, and a 3,500-document run
takes hours. Sage and the worker have to be co-located; everything else is
detail.

**Nothing here is built yet.** Until it is, do not create the A6 worker and do
not set any `SAGE_*` variables. The app runs fine without them — billing runs
generate and sit at `posting_status = pending`.

**Decided 2026-08-28:** VPS in **Germany**, matching Binga, so Railway's
`europe-west` stays ~29 ms away and the Sage worker remains an ordinary Railway
service. O-Billing posts into a **parallel company**, as Binga does.

The trade-off accepted with Germany: interactive Sage use from Zimbabwe is
~180 ms and will feel slow. That is fine while the hosted database is a posting
target. If Zaka staff end up working in it day to day, revisit — Johannesburg is
~30–60 ms from Zimbabwe, at the cost of moving the worker onto the VPS itself,
since Railway has no African region.

### C1. The VPS

A **separate** Contabo VPS from Binga's, in Germany: Windows Server + SQL Server
+ Sage Evolution. Binga's box could technically host Zaka's companies too, and
that would be cheaper, but two councils' financial data on one host means one
compromise or one mistaken `USE` statement reaches both. Separate hosts unless
someone decides otherwise deliberately.

### C2. The two databases

Mirroring Binga:

1. Restore Zaka's Sage company from a council backup — this is the company the
   council works in, and O-Billing never writes to it.
2. Create **`Zaka Rural District Council OBilling`** as a copy of it. This is
   O-Billing's posting target, and the only database `SAGE_WRITE_DATABASE`
   points at.

> **Still unanswered, and it should not stay that way:** how postings in the
> `… OBilling` company reach the council's actual books. Binga has had this
> shape since 2026-07-22 and accumulated 12,069 invoices in the parallel company
> against 1,711 in the council's own; whatever bridges them is not in this repo.
> Establish Zaka's answer before the first live run, not after.

### C3. Access — tailnet, not the public internet

Put the VPS on the tailnet and have the Railway worker reach it by its
`100.x` address. **Do not bind SQL Server to the public interface** — Binga's
does, and answers `sa` from anywhere. An IP allowlist is not a workable
substitute here: Railway egress addresses are not static, which is very likely
how Binga ended up simply opening the port.

Use a dedicated SQL login scoped to the two databases, not `sa`.

### C4. Wiring it up

1. Create the A6 worker (region `europe-west`) and set `SAGE_DB_HOST` (the
   tailnet address), `SAGE_DB_DATABASE`, `SAGE_WRITE_DATABASE`, the SQL
   credentials, `SAGE_MUNI_CODE=ZRDC` and `SAGE_MUNI_NAME=Zaka`, plus
   `DB_QUEUE_RETRY_AFTER=14400` and `SAGE_JOB_MEMORY_LIMIT=2048M`.
2. Zaka's posting map — see **C5**, which is the long pole. Tax types are the
   one thing that carries over unchanged: Zaka's `TaxRate` has `1 = Output Tax`
   at 15% and `7 = Exempt`, matching the committed
   `SAGE_POST_TAXTYPE` / `SAGE_POST_EXEMPT_TAXTYPE` defaults.
3. **Sage → Import from Sage → Import ratepayers (ledger)**, then **Price
   tariffs**, then the per-client charge workbook.
4. A small scoped billing run → **Post to Sage** → verify `InvNum` (DocState 4),
   `PostAR`, `PostGL` and the `Client` balance in the `… OBilling` company.

### C5. The class→item matcher does not transfer to Zaka

Measured 2026-08-29 against `ZAKA Rural District Council (ZiG)`:
`SagePriceImportService::classItemMap()` resolves **1 of 637 client classes —
8 of 21,476 clients**. Binga's coverage is total; Zaka's is nil. Assume nothing
about this working out of the box.

It is not a data problem. Zaka has a USD price list (`_etblPriceListName` id 2)
with 478 priced items, and `billableItems()` finds it correctly.

The cause is `family()`, which takes the **first** alphabetic run of a code as
the service family after stripping `P\dSP\d` segments. Zaka's two sides put
different things first:

| | Code | `family()` |
|---|---|---|
| Class | `RES LEA 504-(P3S` | `RES` — property type |
| Item | `a26-REF-P2SP1` | `REF` — service (`a26` strips out) |

So `RES` is scored against `LEA`/`REF` and can never match. Zaka's service token
is in **second** position on the class side. Binga's codes (`P1SP4-SVS162`,
`ASS R-RES-MED-P3SP3`) yield the same family on both sides, which is why the
matcher was 100% there.

Zaka also models classes differently: one class per **location × charge type**
(`Jerera High Density Lease` / `Refuse` / `Devy Levy` are three classes), so a
ratepayer holds several debtor accounts. That is what produces 637 classes
against Binga's ~30, and it rules out hand-maintaining `posting.class_items`.

The fix is to score **all** tokens on both sides rather than only the first, so
`RES LEA` matches `LEA`. `tests/Unit/SagePriceMatchingTest.php` locks Binga's
current behaviour and must stay green — Binga posts live off this code path.

### C5b. Zaka has no Sage Municipal add-on — the ledger route is the only route

Checked 2026-08-30. Zaka's database is **plain Sage Evolution**; Binga's carries
the Municipal / Electronic-Billing add-on. Every `_mtbl*` and `_ccg_EB_*` table
Binga has is **absent** at Zaka: `_mtblAreas`, `_mtblProperties`,
`_mtblPropertyPortions`, `_mtblPropertyPortionServices`, `_mtblRateTariffs`,
`_mtblRateTariffBands`, `_mtblCategories`, `_mtblBillingRuns`,
`_ccg_EB_Services`.

What that costs, and what it doesn't:

| Path | Zaka | Why |
|---|---|---|
| `SageBillingRunPoster` — posting runs | **works** | every dependency present, `InvNum`/`PostAR`/`PostGL`/`StDfTbl`/`Entities`/`_btblInvoiceLines` included |
| `SageLedgerImportService` — ratepayers | **works** | needs only `Client` + `Areas` |
| `SagePriceImportService` — class prices | **works** | needs `CliClass`, `StkItem`, `_etblPriceListName/Prices` |
| `SageTariffImportService` | **works** | needs `_etblPriceListPrices` |
| `SageImportService` — property/tariff import | **cannot run** | built entirely on `_mtbl*` |
| `SagePropertyWriter` — writing properties back | **cannot run** | built entirely on `_ccg_EB_*` |

So the **ledger** route — which Part C4 already prescribes — is not merely the
recommended path for Zaka, it is the only one. Do not plan any work on the
property-module import or on writing properties into Zaka's Sage unless the
council buys and installs the Municipal add-on.

### C6. Is the ZiG company a fair test of the live one? Yes

Checked 2026-08-29, `ZAKA Rural District Council (ZiG)` against
`ZAKA Rural District Council`. The ZiG copy is a near-exact structural subset,
so matcher work done against it transfers.

| | ZiG | USD | |
|---|---|---|---|
| `CliClass` | 637 | 646 | all 637 ZiG ids exist in USD, **zero code mismatches** |
| `Client` | 21,476 | 21,734 | |
| `StkItem` (distinct codes) | 670 | 671 | one USD-only item, `ad23-OF-P3SP3` |
| `TaxRate` / price lists / `GrpTbl` | 7 / 3 / 52 | 7 / 3 / 52 | identical |

The 9 USD-only classes carry **11 clients between them** — 0.05% — and are the
same shapes as classes ZiG already has (`SHP DEV`, `IND DEV`, `BS LEA`,
`CHUR LEA`, `INS DLV`). A matcher that handles ZiG will handle them. Still,
**re-run coverage against the USD company before go-live** rather than assuming.

### C7. Can Zaka bill like Binga? Not without three changes

The **structure carries over** — that is the important part. Zaka's debtor
accounts are `CUN243-LEA-(P3SP3)`, exactly the `{stand}-{token}-{portion}`
shape `LedgerAccount::split()` expects, and 19,293 of 21,476 accounts (90%)
have the full three segments. So the ledger import's core assumption holds and
this is tuning, not re-architecture.

Three things do need work, in descending order of size:

**1. The class→item matcher** — C5. **Done.** Zaka went from 0.2% to **34.9% of
classes / 62.2% of clients**, with Binga's numbers unchanged to the client
(459/509 and 6,774/10,366 before and after). For scale, Binga posts live at
**90.2% of classes / 65.3% of clients** — so Zaka's client coverage is now
roughly at Binga's operating level, and the remaining gap is the same kind of
gap Binga already runs with.

**2. Billing cadences are missing for 39.5% of accounts.** `TOKEN_FREQUENCIES`
was written from Binga's gazetted schedule and doesn't know Zaka's tokens:

| Token | Accounts | Cadence known? |
|---|---|---|
| `LEA` | 6,069 | yes — annually |
| `DEV` | 4,299 | **no** — Development Levy |
| `LIC` | 3,937 | yes — monthly |
| `REF` | 2,973 | yes — monthly |
| `DLV` | 1,901 | **no** — Development Levy again, second spelling |
| `S/EXT` | 61 | **no** — sand extraction |
| `REN` | 41 | **no** |

`DEV` and `DLV` are the same charge spelled two ways across 6,200 accounts
(29%) and need canonicalising as well as a cadence. The cadences themselves
have to come from **Zaka's own gazetted tariff schedule** — they cannot be
inferred from Binga's, and guessing them silently bills people at the wrong
frequency.

**3. ~2,016 accounts (9%) have no service token at all.** **Done.** They are
two-segment, `{stand}-{portion}`, so `split()` returned the *portion* as the
token — `P3SP3` (916), `P6SP1` (751), `P3SP1` (271), `P2SP1` (53) all appeared
as if they were services, inventing `LEDGER-P3SP3` service types and
mis-grouping those ratepayers. `LedgerAccount` now recognises portion-shaped
segments and yields `(other)`; zero accounts still produce one. Their service
has to come from the client's class instead.

One quirk that shows up in both, worth knowing before it surprises someone:
**`StkItem` codes are not unique.** 713 rows share 670 codes — `dc40-OTL-P3SP3`
covers Electrical Workshop, Peanut Butter Processing *and* Tombstone. Code-keyed
lookups (`$itemRows[$s->Code]`, `keyBy('Code')`) therefore silently keep one row
and drop the rest. That is **safe for GL posting** — checked, and no duplicated
code resolves to more than one revenue account; all of these sit in group 1 →
`SalesAccLink 3443`. It is not necessarily safe for price import, where the
dropped rows could carry different prices, so treat that separately.

---

## Part D — Demo data and test access (REMOVE BEFORE GO-LIVE)

Seeded 2026-08-28 so the panel has something to show before Zaka's real
ratepayers exist. **None of it should survive go-live.**

- **38 customers**, every account number prefixed `DEMO-`, across 7 billing
  wards under Masvingo → Zaka → {Jerera Growth Point, Ndanga, Musiso}.
  48 tariffs; business centres bill USD + ZWG, rural wards USD only.
- **2 completed monthly runs**, 38 invoices each — July (backdated) and the
  current month.
- **A test administrator, `test@obilling.test`, password `password`.**

> The ward and township names are **placeholders**. Jerera, Ndanga and Musiso
> are real Zaka localities, but the ward breakdown under them is invented —
> replace the whole area tree from the council's valuation roll rather than
> correcting it in place.

Two things worth knowing about seeding runs. `BillingRunService::generate()`
refuses a second full-scope run on the same calendar day —
`conflictingRuns()` matches on `orWhereDate('run_at', today)`, not just on
period — so seeding "last month" and "this month" back to back throws
`DuplicateBillingRunException`. Backdating the earlier run's `run_at` into its
own period clears it, and is what history should look like anyway. Don't reach
for `generate($run, force: true)`; that switch exists to override the
double-billing guard, not to make seeding convenient.

To clear it all before importing real ratepayers:

```php
app(App\Support\Tenancy\CurrentMunicipality::class)->runFor($municipalityId, function () {
    App\Models\Invoice::query()->delete();      // invoice_lines cascade
    App\Models\BillingRun::query()->delete();
    App\Models\Customer::query()->delete();     // customer_service cascades
    App\Models\Tariff::query()->delete();
    App\Models\Area::query()->delete();
});

App\Models\User::where('email', 'test@obilling.test')->delete();
```

This leaves the municipality, the area types and service types from
`DefaultSetup`, and the real admin users intact.

---

## Verification

Confirmed on 2026-08-27:

- All four services deploy `SUCCESS` from branch `zaka`.
- `/admin/login` returns 200; the page carries
  `alt="Zaka Rural District Council logo"`, `src="/zaka-logo.png"` and
  `height: 2.75rem`, and `/zaka-logo.png` serves 41,663 bytes of `image/png`.
- `migrate:status` shows all 26 migrations `Ran` in batch 1 against
  `pgsql` / `postgres.railway.internal` / `railway`.
- The three app services carry an identical `APP_KEY` and **no** `SAGE_*`
  variable.

- Municipality `#1 Zaka Rural District Council (ZRDC)` exists — USD/ZWG, VAT
  15%, 4 area types, 4 service types, setup wizard not yet run — and the admin
  user resolves it (`canAccessTenant = true`).
- Authenticated, `/admin` redirects to `/admin/1` and `/admin/1` returns **200**.

Still to confirm once DNS and mail are in place:

- `https://billing.zakardc.co.zw/admin` resolves and serves the same page.
- A schedule created under **Billing → Billing schedules** fires within the hour.
- A password-reset mail actually arrives (needs `MAIL_*`, see below).

## Outstanding

1. **Purge the demo data and delete `test@obilling.test`** — Part D. This one
   gates the CNAME: a `password` login must not be reachable on a public
   hostname.
2. **`MAIL_*` is unset on all three app services.** Password resets and
   notification mail will fail until the council's SMTP is filled in.
3. **The CNAME is not created yet** (A3) — until it is, use
   `obilling-production.up.railway.app`.
4. **Sage**, in full — see Part C.

Not a problem, but worth knowing: Railpack resolves PHP **8.2.33**, because
`composer.json` asks for `^8.2`. The Sage worker image is pinned to 8.4. Binga
resolves the same way, so Zaka is consistent with it rather than divergent.

## Shipping changes

`zaka` is a fast-forward mirror of `main`, so it joins the standard push set:

```
git push origin main \
  && git push upstream main:main \
  && git push upstream main:binga \
  && git push upstream main:gokwe-south \
  && git push upstream main:zaka \
  && git push origin main:gokwe-south \
  && git push origin main:zaka
```

Re-run `php artisan migrate --force` after any deploy that adds migrations.
