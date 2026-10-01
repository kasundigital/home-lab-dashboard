#!/usr/bin/env bash
set -euo pipefail

# Always move to a known-good directory first so this works even when the
# caller's current directory was deleted, moved, or is otherwise inaccessible.
cd /

INSTALL_DIR="${INSTALL_DIR:-/home/docker/homelab}"
IMAGE="${IMAGE:-ghcr.io/kasundigital/home-lab-dashboard:latest}"
PORT="${APP_PORT:-8088}"
TZ_VALUE="${TZ:-Asia/Colombo}"

log() {
  printf '[home-lab-dashboard] %s\n' "$*"
}

fail() {
  printf '[home-lab-dashboard] ERROR: %s\n' "$*" >&2
  exit 1
}

if [ "${EUID}" -ne 0 ]; then
  fail "Run this installer as root or pipe it to sudo bash."
fi

command -v docker >/dev/null 2>&1 || fail "Docker is not installed."
docker compose version >/dev/null 2>&1 || fail "Docker Compose v2 is not available."

log "Preparing ${INSTALL_DIR}"
mkdir -p "${INSTALL_DIR}/data"

# Stop/remove an existing container without touching persistent data.
if docker ps -a --format '{{.Names}}' | grep -qx 'homelab-dashboard'; then
  log "Removing existing homelab-dashboard container"
  docker rm -f homelab-dashboard >/dev/null
fi

# Preserve an existing compose file before replacing it.
if [ -f "${INSTALL_DIR}/compose.yml" ]; then
  backup="${INSTALL_DIR}/compose.yml.backup.$(date +%Y%m%d-%H%M%S)"
  cp "${INSTALL_DIR}/compose.yml" "${backup}"
  log "Existing compose.yml backed up to ${backup}"
fi

cat > "${INSTALL_DIR}/compose.yml" <<EOF
services:
  homelab-dashboard:
    image: ${IMAGE}
    container_name: homelab-dashboard
    restart: unless-stopped
    ports:
      - "${PORT}:80"
    environment:
      TZ: ${TZ_VALUE}
      APP_NAME: Home Lab Dashboard
    volumes:
      - ${INSTALL_DIR}/data:/var/www/data
EOF

log "Pulling latest image"
docker compose -f "${INSTALL_DIR}/compose.yml" pull

log "Starting dashboard"
docker compose -f "${INSTALL_DIR}/compose.yml" up -d --remove-orphans

log "Checking container status"
docker ps --filter name=homelab-dashboard --format 'table {{.Names}}\t{{.Status}}\t{{.Ports}}'

echo
log "Install/update complete."
log "Data directory: ${INSTALL_DIR}/data"
log "Open: http://SERVER_IP:${PORT}"
