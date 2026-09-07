---
paths:
  - '.infrastructure/entrypoint.d/**'
---

# General

## Container boot runs migrate and optimize, not the image's autorun
`AUTORUN_ENABLED=false` on Render: the serversideup image's Laravel automations start five `php artisan` processes per boot, and booting the framework costs ~2.5s each on the free instance. `.infrastructure/entrypoint.d/51-app-boot.sh` runs only `migrate --force` (with two retries, replacing the image's connection probe) and `optimize`.

- The free instance is rebuilt from the image on every wake from sleep, so anything added here is paid on every cold start, not just on deploy.
- Migrations must stay in this script: the free plan has no pre-deploy command, no one-off jobs and no SSH, so there is nowhere else to run them.
- Scripts in `/etc/entrypoint.d/*.sh` run in numeric order and block before s6 starts NGINX and PHP-FPM. `.dockerignore` needs an explicit `!` exception for anything under `.infrastructure/` that the image copies.
