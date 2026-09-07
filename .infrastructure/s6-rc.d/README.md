# Extra s6-overlay services

`serversideup/php:*-fpm-nginx` supervises its processes with s6-overlay. Anything
dropped in here is copied into `/etc/s6-overlay/s6-rc.d/` by the Dockerfile's
`deploy` stage and registered in the `user` bundle, so it starts alongside NGINX
and PHP-FPM.

## queue-worker

Render's free plan only runs web services — a separate Background Worker starts
at $7/mo — so the queue worker rides along inside the web container instead of
getting its own service. Without it nothing ever consumes the `database` queue
and `ScanGmailJob` sits in the `jobs` table forever, leaving "Quét Gmail" stuck
on its progress bar.

- `--timeout=280` must stay below `DB_QUEUE_RETRY_AFTER` (300 on Render), or the
  queue hands the same scan to a second worker while the first is still running.
- `--max-time=3600` retires the worker every hour; s6 restarts it, which releases
  memory and picks up freshly cached config after a deploy.
- Only the `deploy` stage gets this service. Locally the dev stack runs
  `php artisan queue:work` by hand, and the `ci` stage must not spawn workers.
