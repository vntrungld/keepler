#!/usr/bin/env bash
# Registers this git worktree (the original checkout counts as one) with a
# unique Compose project name, Traefik/Mailpit host ports, and a generated
# traefik.yml — so `spin up` can run in several worktrees of this repo at
# the same time without them colliding or cross-routing into each other.
#
# Run it once per worktree, including the very first checkout: Traefik's
# docker provider watches the whole host Docker socket, so even a single
# extra worktree needs every stack (this one included) to be scoped to its
# own COMPOSE_PROJECT_NAME to stay isolated.
#
# Usage: .infrastructure/scripts/spin-worktree-setup.sh [--prune]
#   --prune   Remove registry entries for worktrees that no longer exist.
set -euo pipefail

port_is_free() {
  local port="$1"
  ! (exec 3<>"/dev/tcp/127.0.0.1/${port}") 2>/dev/null
}

repo_root="$(git rev-parse --show-toplevel)"
common_dir="$(cd "$(git rev-parse --git-common-dir)" && pwd)"
registry="${common_dir}/spin-worktree-ports.txt"
touch "$registry"

if [[ "${1:-}" == "--prune" ]]; then
  live_worktrees="$(git worktree list --porcelain | awk '/^worktree /{print $2}')"
  tmp="$(mktemp)"
  while IFS='|' read -r path http https mailpit slug; do
    [[ -z "$path" ]] && continue
    if grep -qxF "$path" <<< "$live_worktrees"; then
      echo "${path}|${http}|${https}|${mailpit}|${slug}" >> "$tmp"
    fi
  done < "$registry"
  mv "$tmp" "$registry"
  echo "Pruned stale worktree entries from ${registry}"
  exit 0
fi

existing="$(awk -F'|' -v p="$repo_root" '$1==p{print;exit}' "$registry")"

if [[ -n "$existing" ]]; then
  IFS='|' read -r _ http_port https_port mailpit_port project_slug <<< "$existing"
  echo "This worktree is already registered."
else
  slot=$(($(wc -l < "$registry") + 1))
  base_slug="$(basename "$repo_root")"
  base_slug="$(printf '%s' "$base_slug" | tr '[:upper:]' '[:lower:]' | tr -c 'a-z0-9' '-')"
  base_slug="${base_slug%-}"

  if [[ "$slot" -eq 1 ]]; then
    # The first worktree ever registered keeps the plain defaults
    # (80/443/8025) so an existing single-checkout setup doesn't change.
    http_port=80
    https_port=443
    mailpit_port=8025
    project_slug="$base_slug"
  else
    while :; do
      http_port=$((8080 + (slot - 2) * 10))
      https_port=$((8443 + (slot - 2) * 10))
      mailpit_port=$((9025 + (slot - 2) * 10))
      if port_is_free "$http_port" && port_is_free "$https_port" && port_is_free "$mailpit_port"; then
        break
      fi
      slot=$((slot + 1))
    done
    # Suffixed with the slot number so it can never collide with another
    # worktree's slug, even if two worktree directories sanitize the same way.
    project_slug="${base_slug}-${slot}"
  fi
  echo "${repo_root}|${http_port}|${https_port}|${mailpit_port}|${project_slug}" >> "$registry"
fi

env_file="${repo_root}/.env"
if [[ ! -f "$env_file" ]]; then
  cp "${repo_root}/.env.example" "$env_file"
fi

set_env() {
  local key="$1" value="$2"
  if grep -q "^${key}=" "$env_file"; then
    sed -i.bak "s|^${key}=.*|${key}=${value}|" "$env_file" && rm -f "${env_file}.bak"
  else
    printf '%s=%s\n' "$key" "$value" >> "$env_file"
  fi
}

set_env "COMPOSE_PROJECT_NAME" "$project_slug"
set_env "SPIN_HTTP_PORT" "$http_port"
set_env "SPIN_HTTPS_PORT" "$https_port"
set_env "SPIN_MAILPIT_PORT" "$mailpit_port"
set_env "APP_URL" "http://localhost:${http_port}"

traefik_dir="${repo_root}/.infrastructure/conf/traefik/dev"
sed "s/__COMPOSE_PROJECT_NAME__/${project_slug}/g" \
  "${traefik_dir}/traefik.yml.example" > "${traefik_dir}/traefik.yml"

echo "Worktree: ${repo_root}"
echo "  Project: ${project_slug}"
echo "  HTTP:    http://localhost:${http_port}"
echo "  HTTPS:   https://localhost:${https_port}"
echo "  Mailpit: http://localhost:${mailpit_port}"
echo ""
echo "Run 'spin up --build' in this worktree to start its stack."
