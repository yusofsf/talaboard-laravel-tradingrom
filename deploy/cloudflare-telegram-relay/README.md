# Private Telegram relay on Cloudflare Workers

This Worker lets an Iranian VPS call the Telegram Bot API without exposing the
bot token in the relay URL. Requests are authenticated with a separate secret.

## Deploy

From this directory, authenticate and deploy:

```bash
npx wrangler@latest login
npx wrangler@latest secret put TELEGRAM_BOT_TOKEN
npx wrangler@latest secret put RELAY_SECRET
npx wrangler@latest deploy
```

For the initial deployment, `RELAY_SECRET` may use the bot token so no extra
credential has to be transferred to the VPS. A separate randomly generated
value is preferred for long-term use. Optionally restrict the Worker to the VPS
public IP by adding an `ALLOWED_IP` variable in the Cloudflare dashboard.

Test the returned `workers.dev` URL:

```bash
curl https://YOUR-WORKER.workers.dev/health
```

Then configure the Laravel application on the VPS:

```dotenv
TELEGRAM_API_URL=https://YOUR-WORKER.workers.dev/telegram
# Optional when RELAY_SECRET initially matches TELEGRAM_BOT_TOKEN:
TELEGRAM_RELAY_SECRET=
TELEGRAM_PROXY=
```

Apply the production configuration and restart the queue worker:

```bash
php artisan optimize:clear
php artisan config:cache
sudo supervisorctl restart talaboard-telegram-worker:*
```

Keep both `TELEGRAM_BOT_TOKEN` and `RELAY_SECRET` out of Git and Cloudflare
plaintext variables. Add them as encrypted Worker secrets.
