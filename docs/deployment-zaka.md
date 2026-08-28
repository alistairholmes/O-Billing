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

> **Put this service in the Railway region closest to Zaka's own VPS.** Zaka's
> Sage sits on a **different VPS to Binga's**, so do not copy Binga's
> `europe-west` blindly — look up where Zaka's VPS actually is, then pick the
> nearest region.
>
> This one setting is the biggest posting-speed lever there is, because posting
> is thousands of sequential round-trips and the round-trip is dominated by
> worker↔VPS distance. Measured on Binga (VPS in Germany): ~29 ms/round-trip
> from EU-West vs ~184 ms from US-East — 6×, which was ~15 min vs ~2.5 h on a
> 3,500-document run. Same physics, different geography, for Zaka.

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

**Zaka has no Sage database linked yet, and its Sage will live on a different
VPS to Binga's.** Binga's Contabo box (`161.97.178.181:1433`) is not the target —
confirmed on 2026-08-27 that it hosts only Binga's companies. Zaka gets its own
host, its own tunnel, and its own worker region.

Until that VPS exists and the council's Sage Evolution company is restored onto
it, do **not** create the A6 worker and do **not** set any `SAGE_*` variables.
The app runs fine without them: billing runs generate and sit at
`posting_status = pending`.

When Sage does arrive, the remaining work is:

1. Stand up the tunnel to Zaka's VPS, then set `SAGE_DB_HOST` /
   `SAGE_DB_DATABASE` / `SAGE_WRITE_DATABASE` / credentials on the A6 worker,
   plus `SAGE_MUNI_CODE=ZRDC` and `SAGE_MUNI_NAME=Zaka`. Set the worker's
   region from where that VPS is (see A6).
2. Zaka's posting map in `config/sage.php` — `posting.class_items` (client class
   → Sage `StkItem` code), tax types and currency — from the council's Sage
   price list. Binga's map is checked in as the default and **will not** be
   right for Zaka.
3. **Sage → Import from Sage → Import ratepayers (ledger)**, then **Price
   tariffs**, then the per-client charge workbook.
4. A small scoped billing run → **Post to Sage** → verify `InvNum` (DocState 4),
   `PostAR`, `PostGL` and the `Client` balance in Zaka's Sage.

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
