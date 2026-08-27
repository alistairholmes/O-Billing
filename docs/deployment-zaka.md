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
| Railway project | `132dd488-6c72-4860-86da-9be498656bd6` (env `9f12c910-f445-49a8-8d76-52692d8aaa72`) |
| Railway account | `alistair.holmes@olimement.com` — **not** `olimemdevelopers@gmail.com` |
| Domain | `billing.zakardc.co.zw` |
| Municipality code | `ZRDC` |
| Sage | **not yet provisioned**, and on a **different VPS** to Binga's — see [Part C](#part-c--sage-pending) |

---

## Part A — Cloud app on Railway

Four services, all from the `zaka` branch of
`Olimem-Enterprise-Solutions/O-Billing`.

### A1. Postgres

Add a **PostgreSQL** database to the project. Everything else references it as
`${{Postgres.DATABASE_URL}}`; the external `DATABASE_PUBLIC_URL` is only needed
if something outside Railway has to connect.

### A2. Web service (`O-Billing`)

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

Then, once: migrate and create the first admin.

```
php artisan migrate --force && php artisan config:cache && php artisan filament:optimize
php artisan user:provision "Full Name" user@example.com --admin
```

`user:provision` prints a generated password once and attaches the municipality
automatically. Further users are provisioned from the panel's **Users** page.

### A3. Domain

Add `billing.zakardc.co.zw` to the web service and create the CNAME Railway
gives you in the `zakardc.co.zw` zone. TLS is automatic.

### A4. Default-queue worker

Second service, same source, start command:

```
php artisan queue:work --queue=default --tries=3 --timeout=120
```

**Never let this service listen on `sage`.**

### A5. Scheduler

Third service, same source:

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

## Verification

- `https://billing.zakardc.co.zw/admin` loads, logs in, tenant reads **Zaka**.
- The sidebar shows Zaka's crest and `Zaka Rural District Council` — not Binga's.
- No `SAGE_*` variable exists on the web, default-worker or scheduler services.
- A schedule created under **Billing → Billing schedules** fires within the hour.

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
