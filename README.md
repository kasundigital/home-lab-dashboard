# Home Lab Dashboard

A lightweight self-hosted dashboard for a home lab, household bills, services, media links, and server status.

## v0.2.0

Initial foundation includes:

- Responsive compact dashboard\n- Settings page for app URLs, API keys/tokens, credentials and integration enable/disable
- Dark/light theme
- Household bill tracking with paid/unpaid status
- Monthly totals and upcoming due bills
- Service shortcuts for media and lab apps
- SQLite persistence
- Docker deployment
- Version/build display
- Mobile-first layout

## Quick start

### Recommended: prebuilt Docker image

No Git clone or local build is required.

```bash
mkdir -p /home/docker/homelab/data
cd /home/docker/homelab

cat > compose.yml <<'EOF'
services:
  homelab-dashboard:
    image: ghcr.io/kasundigital/home-lab-dashboard:latest
    container_name: homelab-dashboard
    restart: unless-stopped
    ports:
      - "8088:80"
    environment:
      TZ: Asia/Colombo
      APP_NAME: Home Lab Dashboard
    volumes:
      - ./data:/var/www/data
EOF

docker compose pull
docker compose up -d
```

Open:

```text
http://SERVER_IP:8088
```

### Docker run

```bash
mkdir -p /home/docker/homelab/data

docker run -d \
  --name homelab-dashboard \
  --restart unless-stopped \
  -p 8088:80 \
  -e TZ=Asia/Colombo \
  -e APP_NAME="Home Lab Dashboard" \
  -v /home/docker/homelab/data:/var/www/data \
  ghcr.io/kasundigital/home-lab-dashboard:latest
```

### Update

```bash
cd /home/docker/homelab
docker compose pull
docker compose up -d
```

The GitHub Actions workflow automatically builds and publishes multi-architecture images for `linux/amd64` and `linux/arm64` to GitHub Container Registry after pushes to `main` and version tags.

Default local deployment does not expose the database outside the container stack.

## Data

Persistent SQLite data is stored in the Docker volume mapped to `./data`.

## Roadmap

- Live server CPU/RAM/temperature/disk metrics
- Docker container status
- Storage health and SMART summary
- Bill attachments/PDFs
- Usage charts for electricity/water/internet
- Notifications and due-date reminders
- n8n integration
- Solar/PV summary integration
- Authentication and user management
- Backup/restore
- Configurable dashboard modules

## License

MIT

---

Designed & Developed by [Kasun Indika](https://www.kasunindika.com)
