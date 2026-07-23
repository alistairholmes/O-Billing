# O-Billing Deployment Runbook — Binga RDC

**Model:** one instance per council. The **app UI + database** run in the cloud
(Railway); a **headless worker** on **Olimem's own server on the Binga LAN** does
all Sage work locally and talks to the cloud only outbound. No O-Billing code
runs on any council machine.

```
Internet ─https─> Railway  binga.obilling.<domain>
                   • Filament UI (no sqlsrv driver)
                   • Postgres  (customers, billing_runs, invoices, users, jobs)
                   • web + default-queue worker (NEVER the 'sage' queue)
                        ▲ outbound TLS (worker polls 'sage' queue, writes results)
                   [Binga LAN] Olimem server
                   • queue:work --queue=sage   (NSSM Windows service)
                   • sqlsrv ─> Council SQL Server / Sage Evolution (LAN speed)
```

Heavy Sage traffic never leaves the LAN; only small job/result rows cross the
internet. The worker opens no inbound ports at Binga.

---

## 0. Prerequisites / decisions

- A domain you control (for `binga.obilling.<domain>`) and access to its DNS.
- A Railway account (or any host that runs a Docker image + managed Postgres).
- Olimem's Binga server: on the **same LAN as the council's Sage SQL Server**,
  always-on, with **outbound** internet to Railway.
- Binga's Sage details: SQL Server host/instance, the live company database
  name, and auth (Windows-trusted or a SQL login).
- Binga's posting map: the Sage service-item (`StkItem`) codes + price list
  (the same kind of data as the Gokwe `Billing.Groups2` workbook) — needed for
  `config/sage.php` `posting.class_items`, tax types and currency.

---

## Part A — Cloud app on Railway

### A1. Postgres
Create a **PostgreSQL** database in the Railway project. Note its connection
variables (Railway exposes `DATABASE_URL`).

### A2. Build image
The cloud image needs PHP 8.4 + the Vite build, **not** the SQL Server driver.
Add this `Dockerfile` to the repo root:

```dockerfile
# --- asset build ---
FROM node:20-alpine AS assets
WORKDIR /app
COPY package*.json vite.config.js ./
RUN npm ci
COPY resources ./resources
COPY . .
RUN npm run build

# --- runtime (Laravel-ready php-fpm + nginx) ---
FROM serversideup/php:8.4-fpm-nginx
WORKDIR /var/www/html
USER root
COPY --chown=www-data:www-data . .
COPY --chown=www-data:www-data --from=assets /app/public/build ./public/build
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts
USER www-data
```

(No `pdo_sqlsrv` here — the cloud app never touches Sage.)

### A3. Web service
Deploy the repo (`binga` branch) as a Railway service using the Dockerfile.
Set variables:

| Variable | Value |
|---|---|
| `APP_NAME` | `O-Billing — Binga` |
| `APP_ENV` | `production` |
| `APP_KEY` | `base64:…` (generate with `php artisan key:generate --show`) |
| `APP_DEBUG` | `false` |
| `APP_URL` | `https://binga.obilling.<domain>` |
| `DB_CONNECTION` | `pgsql` |
| `DB_URL` | `${{Postgres.DATABASE_URL}}` |
| `DB_SSLMODE` | `require` |
| `SESSION_DRIVER` `QUEUE_CONNECTION` `CACHE_STORE` | `database` |
| `MAIL_*` | your SMTP (for password resets / notifications) |

**Do NOT set any `SAGE_*` variables on the cloud service** — it must never try
to reach Sage.

Run migrations once (Railway one-off shell or a deploy command):
```
php artisan migrate --force && php artisan config:cache && php artisan filament:optimize
```
Create the first admin user (one-off shell — no tinker needed):
```
php artisan user:provision "Full Name" user@example.com --admin
```
This attaches the (single) Binga municipality automatically and prints a
generated password once. Promote an existing user instead with
`php artisan user:make-admin user@example.com`. Administrators can then
provision further users from the panel's **Users** page (Administration
group) — no shell required.

### A4. Domain
Add the custom domain `binga.obilling.<domain>` to the web service and create the
CNAME Railway gives you. TLS is automatic.

### A5. Cloud default-queue worker
Add a second Railway service from the same image with start command:
```
php artisan queue:work --queue=default --tries=3 --timeout=120
```
This drains **non-Sage** jobs only. **It must never listen on `sage`.**

### A6. Scheduler (recurring billing schedules)
Add a third Railway service from the same image with start command:
```
php artisan schedule:work
```
This fires the hourly `billing:run-scheduled` command, which creates (and, for
schedules with auto-post on, queues to Sage) the billing runs defined under
**Billing → Billing schedules**. It needs **no Sage connection** — auto-post is
dispatched to the `sage` queue and executed by the on-site worker.

---

## Part B — On-site Sage worker (Olimem's Binga server)

### B1. Install runtime (once)
- PHP 8.4 (NTS x64) — add to PATH.
- Microsoft **ODBC Driver 18 for SQL Server**.
- **`pdo_sqlsrv` + `sqlsrv`** PHP extensions (copy the matching NTS x64 DLLs into
  `C:\php\ext`, enable in `php.ini`) — same as the O-Billing dev setup.
- Composer.
- `php -m` must list `pdo_sqlsrv` and `sqlsrv`.

### B2. Deploy the code (locked down)
Clone the repo (`binga` branch) to a folder owned by an **Olimem-only** Windows
account; set NTFS permissions so council users can't read it. `composer install
--no-dev --optimize-autoloader`.

### B3. `.env` (worker)
```
APP_NAME="O-Billing — Binga"
APP_ENV=production
APP_KEY=<same APP_KEY as the cloud service>
APP_DEBUG=false

# Shared cloud database + queue (outbound to Railway Postgres)
DB_CONNECTION=pgsql
DB_URL=<Railway Postgres external connection URL>
DB_SSLMODE=require
QUEUE_CONNECTION=database

# Council's Sage SQL Server (LAN)
SAGE_DB_HOST=SAGE-SERVER\SQLEXPRESS
SAGE_DB_DATABASE="Binga Rural District Council NCOA"
SAGE_WRITE_DATABASE="Binga Rural District Council NCOA"
SAGE_DB_USERNAME=        # blank = Windows trusted auth
SAGE_DB_PASSWORD=
SAGE_DB_ENCRYPT=no
SAGE_DB_TRUSTED=true
SAGE_DB_QUERY_TIMEOUT=120
SAGE_MUNI_CODE=BRDC
SAGE_MUNI_NAME="Binga"
# SAGE_POST_TAXTYPE / _EXEMPT_TAXTYPE / _CURRENCY / _AGENT / _USERNAME per Binga's Sage
```
The worker uses the **same `APP_KEY` and the same Postgres** as the cloud app —
that's how they share jobs and data. Do **not** run migrations from the worker.

### B4. Run as a service (NSSM)
Install [NSSM], then:
```
nssm install ObillingSageWorker "C:\php\php.exe" ^
  "C:\path\to\obilling\artisan" queue:work --queue=sage --tries=1 --timeout=0 --sleep=3
nssm set ObillingSageWorker AppDirectory "C:\path\to\obilling"
nssm set ObillingSageWorker Start SERVICE_AUTO_START
nssm start ObillingSageWorker
```
`--tries=1` is deliberate: Sage posting must never silently retry (the poster's
double-post guard is the only safe re-entry). Restart the service after any code
update (`git pull` + `composer install` + `nssm restart ObillingSageWorker`).

---

## Part C — Binga data & config

1. **Posting config:** set Binga's `posting.class_items`, tax types and currency
   in `config/sage.php` (or via `SAGE_POST_*`) using Binga's Sage price list.
2. **Import ratepayers:** in the UI, **Sage → Import from Sage → Import ratepayers
   (ledger)**. The worker runs it; watch **Sage → Sage Operations** for the result.
3. **Price tariffs:** **Import from Sage → Price tariffs**.
4. **Import per-client charges:** place Binga's charge workbook where the worker
   can read it and run the charge import (CLI `sage:import-charges <path>` on the
   worker, or a queued job once file transfer is wired).
5. **Test billing + posting:** create a small scoped billing run in the UI →
   **Post to Sage** (queues it) → verify in Part D.

---

## Part D — Verification (end-to-end)

- **App up:** `https://binga.obilling.<domain>/admin` loads; log in; tenant = Binga.
- **Bridge alive:** trigger a ledger import; the on-site worker picks up the
  `sage` job and populates the cloud Postgres; **Sage Operations** shows `done`
  and a bell notification arrives.
- **Posting proof:** post a small run; on Binga's Sage confirm `InvNum`
  (DocState 4), `PostAR`, `PostGL`, and the `Client` balance are written (the
  same checks used for Gokwe's `INV00000120`), and the run shows **posting_status
  = posted**.
- **Resilience:** `nssm restart ObillingSageWorker` mid-queue → jobs resume.
- **Isolation:** confirm no inbound ports were opened at Binga and no `SAGE_*`
  vars exist on the cloud services.

---

## Security checklist
- Railway Postgres over TLS (`DB_SSLMODE=require`); strong credentials; if
  possible, IP-allowlist the Binga server's egress IP.
- Secrets only in Railway/worker env — never committed.
- Cloud services carry **no** `SAGE_*` vars and **no** sqlsrv driver.
- Worker code folder is readable only by the Olimem service account.
- Worker is outbound-only; no port-forwarding or inbound rules at Binga.

## Operations
- **Worker logs:** `storage/logs/laravel.log` on the Binga server; NSSM can also
  capture stdout (`nssm set ObillingSageWorker AppStdout <file>`).
- **Redeploy cloud:** push to `binga` → Railway rebuilds; re-run `migrate --force`
  if there are new migrations.
- **Redeploy worker:** `git pull` + `composer install --no-dev` +
  `nssm restart ObillingSageWorker`.

## Onboarding another council
Repeat with a **new Railway project + Postgres + subdomain** and a **worker on
that council's Olimem server**, with that council's `SAGE_*` and posting config.
One instance and one worker per council.

[NSSM]: https://nssm.cc/
