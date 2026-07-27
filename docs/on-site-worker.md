# On-site Sage worker (fast posting)

Posting a billing run writes ~6 rows per invoice document to Sage. Over the
Tailscale tunnel each row is a ~180–400 ms round-trip, so a full run takes
**hours**. Run the worker on a machine **on the Sage LAN** and each round-trip
is sub-millisecond — the same run posts in **minutes**.

Nothing in the app changes. The cloud app just drops "post" jobs on the shared
`sage` queue (in the Railway Postgres); whichever worker drains that queue does
the actual Sage writing. Move that worker on-site and every post is fast — no
matter where the person who clicked **Post to Sage** is.

```
wisdom (anywhere) → Post to Sage → job on the `sage` queue (Railway Postgres)
                                        ↓  (drained by…)
                          on-site worker → Sage over the LAN  [fast]
```

This guide runs the worker as a Docker container **on the Sage server itself**
(Windows). The container reaches SQL Server on the same box via
`host.docker.internal`.

---

## Prerequisites (one-time)

1. **SQL Server accepts TCP on 1433.** It already does (remote posting works via
   the forwarder). If ever in doubt: SQL Server Configuration Manager → *SQL
   Server Network Configuration* → *Protocols* → **TCP/IP = Enabled**, listening
   on port **1433**; restart the SQL Server service after any change.
2. **Windows Firewall** allows inbound TCP **1433** (a rule almost certainly
   exists already since remote connections work).
3. **Docker Desktop for Windows** installed, using the **WSL2** backend (our
   image is Linux-based). Verify it works:
   ```
   docker run --rm hello-world
   ```
4. **Git** installed (`git --version`).

---

## Step 1 — Get the code

Open **PowerShell** on the Sage server:

```powershell
cd C:\
git clone --branch binga https://github.com/Olimem-Enterprise-Solutions/O-Billing.git
cd O-Billing
```

## Step 2 — Create the worker config

Create a file `C:\O-Billing\worker.env` (plain text, no quotes around values).
Copy the **secret** values (`APP_KEY`, `DB_URL`, `SAGE_DB_PASSWORD`) from the
Railway dashboard — the existing worker service **meticulous-wonder → Variables**
— with **two changes** noted below:

```ini
APP_ENV=production
APP_DEBUG=false
APP_KEY=            # copy from Railway (meticulous-wonder → Variables)
LOG_CHANNEL=stderr

# Shared cloud database + queue.
# IMPORTANT: use the PUBLIC url — Railway → Postgres → Variables → DATABASE_PUBLIC_URL
# (turntable.proxy.rlwy.net:…). NOT postgres.railway.internal, which only works
# inside Railway.
DB_CONNECTION=pgsql
DB_URL=             # DATABASE_PUBLIC_URL from the Railway Postgres service
DB_SSLMODE=prefer
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=14400

# Sage over the LAN. This box hosts SQL Server, so reach it via the Docker host.
SAGE_DB_HOST=host.docker.internal
SAGE_DB_PORT=1433
SAGE_DB_DATABASE=Binga Rural District Council OBilling
SAGE_WRITE_DATABASE=Binga Rural District Council OBilling
SAGE_DB_USERNAME=sa
SAGE_DB_PASSWORD=   # copy from Railway
SAGE_DB_ENCRYPT=no
SAGE_DB_TRUSTED=false
SAGE_DB_QUERY_TIMEOUT=120

SAGE_MUNI_CODE=BRDC
SAGE_MUNI_NAME=Binga RDC

# Fast LAN → larger batches, generous memory.
SAGE_JOB_MEMORY_LIMIT=2048M
SAGE_POST_BATCH=250
```

The two on-site changes vs the cloud worker: **`DB_URL`** must be the *public*
Postgres URL, and **`SAGE_DB_HOST=host.docker.internal`** (the local Sage box)
instead of the tunnel IP.

## Step 3 — Build the image

```powershell
docker build -f docker/worker.Dockerfile -t obilling-worker:latest .
```

## Step 4 — Run the worker

```powershell
docker run -d `
  --name obilling-sage-worker `
  --restart unless-stopped `
  --add-host host.docker.internal:host-gateway `
  --env-file worker.env `
  obilling-worker:latest
```

- `--restart unless-stopped` → auto-starts on reboot / after a crash (make sure
  Docker Desktop is set to **start on login/boot**).
- `--add-host host.docker.internal:host-gateway` → lets the Linux container reach
  SQL Server on the Windows host.

## Step 5 — Verify it's connected and draining the queue

```powershell
docker logs -f obilling-sage-worker
```

You should see the queue worker start with no errors and sit idle
(`Processing…` lines only appear when a job arrives). To prove the Sage link:

```powershell
docker exec obilling-sage-worker php artisan tinker --execute="echo \App\Models\Sage\Client::count().' Sage clients reachable';"
```

A number means the container talks to Sage over the LAN. If it errors on the
connection, see **Troubleshooting** below.

## Step 6 — Stop the cloud worker from competing

While both the cloud worker (`meticulous-wonder`) and this on-site worker drain
the same `sage` queue, the slow cloud one may grab a job first. Once the on-site
worker is confirmed working, in Railway **remove or pause `meticulous-wonder`**
(or change its start command to `--queue=default` so it only drains non-Sage
jobs). From then on, every post runs on-site and fast.

## Step 7 — Test end to end

In the panel (as wisdom, from anywhere): open a completed billing run → **Post to
Sage**. Watch `docker logs -f obilling-sage-worker` on the server — it should
pick the job up within seconds and post the whole run in minutes. Progress and
the final result appear under **Sage → Sage Operations** and the notification
bell.

---

## Updating the worker later

```powershell
cd C:\O-Billing
git pull
docker build -f docker/worker.Dockerfile -t obilling-worker:latest .
docker rm -f obilling-sage-worker
docker run -d --name obilling-sage-worker --restart unless-stopped `
  --add-host host.docker.internal:host-gateway --env-file worker.env obilling-worker:latest
```

## Troubleshooting

- **`Login timeout expired` / can't reach Sage from the container** — the
  container can't see the host's SQL Server. Confirm SQL Server listens on TCP
  1433, the firewall allows it, and try the Sage server's LAN IP in place of
  `host.docker.internal` (e.g. `SAGE_DB_HOST=192.168.1.50`).
- **Can't reach the database / queue** — `DB_URL` must be the **public** Railway
  Postgres URL (`DATABASE_PUBLIC_URL`), not `postgres.railway.internal`.
- **Jobs stay queued, worker idle** — the worker only drains `sage`; a "post" is
  a `sage` job, so it should pick up. Confirm the container is running
  (`docker ps`) and its logs show no boot error.
- **`APP_KEY` errors** — copy the exact `APP_KEY` from the Railway worker so it
  matches the cloud app.

## Requirements recap

- Machine on the **same LAN** as Sage, **always on**, with **outbound internet**
  (to reach the Railway queue). If its internet drops, posts simply wait in the
  queue until it's back — nothing is lost.
