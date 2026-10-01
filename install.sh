#!/usr/bin/env bash
set -euo pipefail

INSTALL_DIR="/home/docker/homelab"
IMAGE="ghcr.io/kasundigital/home-lab-dashboard:latest"
PORT="${APP_PORT:-8088}"
TZ_VALUE="${TZ:-Asia/Colombo}"

if ! command -v docker >/dev/null 2>&1; then
  echo "Docker is not installed. Install Docker first."
  exit 1
fi

if ! docker compose version >/dev/null 2>&1; then
  echo "Docker Compose v2 is not available."
  exit 1
fi

mkdir -p "${INSTALL_DIR}/data"
cd "${INSTALL_DIR}"

if [ -f compose.yml ]; then
  cp compose.yml "compose.yml.backup.$(date +%Y%m%d-%H%M%S)"
fi

cat > compose.yml <<EOF
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
      - ./data:/var/www/data
EOF

docker rm -f homelab-dashboard >/dev/null 2>&1 || true
docker compose pull
docker compose up -d

echo
echo "Home Lab Dashboard is running."
echo "Open: http://SERVER_IP:${PORT}"
echo "Data: ${INSTALL_DIR}/data"
