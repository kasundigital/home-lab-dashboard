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

```bash
mkdir -p /home/docker/homelab
cd /home/docker/homelab
git clone https://github.com/kasundigital/home-lab-dashboard.git .
cp .env.example .env
docker compose up -d --build
```

Open:

```text
http://SERVER_IP:8088
```

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
