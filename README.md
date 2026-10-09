# Home Lab Dashboard

A lightweight self-hosted dashboard for a home lab, household bills, services, media links, and server status.

## v0.3.0

- **Bills from n8n**: your n8n workflows push bills to `/api/bills.php`. Unpaid bills show **red**, paid bills turn **green**
- Mark bills paid/unpaid from the dashboard, the API, or Telegram (`/paid 12`)
- **Telegram bot**: tracks every user who messages your bot, alerts you about new users, and shows messages only from the users you select
- Telegram admin commands: `/bills`, `/paid <id>`, `/unpaid <id>`, `/status`, `/users`, `/watch @user`, `/unwatch @user`
- **Discord**: alerts to a channel through a webhook
- Choose per event (new bill, bill paid, daily due reminder, new Telegram user, message from selected user) whether it goes to Telegram, Discord, or both
- Daily reminder of overdue and soon-due bills
- Optional callback webhook to n8n when you change a bill's status on the dashboard
- Dashboard refreshes itself every 60 seconds
- Settings page for app URLs, API keys/tokens and integrations
- Dark/light theme, mobile-first layout, SQLite persistence, Docker deployment

## Quick start

### One-command install

Run this from any directory. The installer uses absolute paths and does not depend on your current working directory.

```bash
curl -fsSL https://raw.githubusercontent.com/kasundigital/home-lab-dashboard/main/install.sh | sudo bash
```

It installs/updates the dashboard under `/home/docker/homelab` and preserves `/home/docker/homelab/data`.


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

## Sending bills from n8n

Find your API token under **Settings → API for n8n**. At the end of your billing workflow, add an **HTTP Request** node:

- Method: `POST`
- URL: `http://SERVER_IP:8088/api/bills.php`
- Header: `Authorization: Bearer <API token>`
- Body (JSON):

```json
{
  "external_id": "EDL-1234567-2026-10",
  "provider": "EDL",
  "category": "Electricity",
  "account_no": "1234567",
  "billing_month": "2026-10",
  "amount": 1000,
  "due_date": "2026-10-25",
  "status": "unpaid"
}
```

The `external_id` identifies the bill. Sending the same `external_id` again updates that bill instead of adding a duplicate. When it's paid, send `{"external_id": "EDL-1234567-2026-10", "status": "paid"}` and the bill turns green. A paid bill stays paid even if a later run sends `unpaid`; add `"reopen": true` to reopen it on purpose. To send several bills at once, wrap them in `{"bills": [...]}`. Without an `external_id`, the dashboard identifies the bill by `provider + account_no + billing_month`.

`GET /api/bills.php?status=unpaid` returns the bill list as JSON.

## Telegram

1. Create a bot with **@BotFather** and paste its token in **Settings → Telegram & Discord**.
2. Choose how the dashboard receives messages:
   - **Dashboard reads the bot directly (polling)**: use this when only the dashboard uses the bot.
   - **n8n forwards updates**: use this when the bot already sends its updates to an n8n **Telegram Trigger**. Telegram allows only one receiver per bot. Add an HTTP Request node after the trigger: `POST /api/telegram.php` with the same `Authorization` header and body `{{ JSON.stringify($json) }}`.
3. Send `/start` to the bot. Your user ID appears on the **Telegram** page. Add it to **Admin chat IDs** so you receive alerts and can use the commands.
4. On the **Telegram** page, click **Show messages** for each user whose messages you want on the dashboard.

Only admin chat IDs can run commands. Messages from everyone else are stored and never answered by the dashboard.

## Discord

Channel settings → **Integrations → Webhooks → New Webhook**, copy the URL, paste it in Settings, and press **Test Discord**.

## Background worker

The container runs a small background worker next to Apache. It polls Telegram and sends the daily reminders. Its status is shown on the Settings page. Set `WORKER_ENABLED=0` to turn it off.

## Security

This dashboard has no login. Keep it on your LAN and reach it from outside only through your VPN. Never forward port 8088 on your router. The API endpoints require the API token.

## Roadmap

- Live server CPU/RAM/temperature/disk metrics
- Docker container status
- Storage health and SMART summary
- Bill attachments/PDFs
- Usage charts for electricity/water/internet
- Solar/PV summary integration
- Authentication and user management
- Backup/restore
- Configurable dashboard modules

## License

MIT

---

Designed & Developed by [Kasun Indika](https://www.kasunindika.com)
