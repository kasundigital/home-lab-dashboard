#!/bin/sh
set -eu

mkdir -p /var/www/data
chown -R www-data:www-data /var/www/data

# Background worker (Telegram polling, bill reminders), restarted whenever it exits.
if [ "${WORKER_ENABLED:-1}" = "1" ]; then
  (
    while true; do
      runuser -u www-data -- php /var/www/bin/worker.php || true
      sleep 5
    done
  ) &
fi

exec "$@"
