---
paths:
  - 'app/Jobs/**'
---

# Jobs

## Queued jobs run inside the web container, not a separate worker
Production (Render free) has no Background Worker service — the cheapest one is $7/mo. `php artisan queue:work` runs as an s6-overlay service inside the web container instead; see `.infrastructure/s6-rc.d/queue-worker/`, installed by the Dockerfile's `deploy` stage only.

Consequences for any queued job:
- The worker runs `--timeout=280`, which must stay below `DB_QUEUE_RETRY_AFTER` (300 on Render) or the queue hands one job to two workers.
- The free instance sleeps after 15 min with no inbound requests, killing in-flight jobs. An external scheduler pings `/cron/warm` every 10 min between 07:00 and 23:59 (Asia/Ho_Chi_Minh) to hold it awake, but that is best-effort — it sleeps overnight anyway, the ping can lapse, and Render restarts the instance on its own. Still only safe for work a user is actively waiting on (the dashboard polls every 1s, which keeps the container awake). Do not queue anything that must run unattended — use the HTTP-triggered `/cron/*` endpoints for that.
- Give every job a `failed()` handler. A `try/catch` in `handle()` does not run when the process is killed outright, and any row the UI polls would otherwise stay stuck forever.
