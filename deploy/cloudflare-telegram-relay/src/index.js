const TELEGRAM_METHOD = /^[A-Za-z][A-Za-z0-9_]{0,63}$/;
const MAX_BODY_BYTES = 1024 * 1024;

async function secureEqual(left, right) {
  const encoder = new TextEncoder();
  const [leftHash, rightHash] = await Promise.all([
    crypto.subtle.digest('SHA-256', encoder.encode(left)),
    crypto.subtle.digest('SHA-256', encoder.encode(right)),
  ]);
  const leftBytes = new Uint8Array(leftHash);
  const rightBytes = new Uint8Array(rightHash);
  let difference = 0;

  for (let index = 0; index < leftBytes.length; index += 1) {
    difference |= leftBytes[index] ^ rightBytes[index];
  }

  return difference === 0;
}

function json(payload, status = 200) {
  return Response.json(payload, {
    status,
    headers: {
      'Cache-Control': 'no-store',
      'X-Content-Type-Options': 'nosniff',
    },
  });
}

export default {
  async fetch(request, env) {
    const url = new URL(request.url);

    if (request.method === 'GET' && url.pathname === '/health') {
      return json({ ok: true });
    }

    if (request.method !== 'POST' || !url.pathname.startsWith('/telegram/')) {
      return json({ ok: false, error: 'Not found.' }, 404);
    }

    if (!env.TELEGRAM_BOT_TOKEN || !env.RELAY_SECRET) {
      return json({ ok: false, error: 'Relay is not configured.' }, 503);
    }

    const suppliedSecret = request.headers.get('X-Telegram-Relay-Secret') ?? '';
    if (!await secureEqual(suppliedSecret, env.RELAY_SECRET)) {
      return json({ ok: false, error: 'Unauthorized.' }, 401);
    }

    if (env.ALLOWED_IP && request.headers.get('CF-Connecting-IP') !== env.ALLOWED_IP) {
      return json({ ok: false, error: 'Unauthorized.' }, 401);
    }

    const method = url.pathname.slice('/telegram/'.length);
    if (!TELEGRAM_METHOD.test(method)) {
      return json({ ok: false, error: 'Invalid Telegram method.' }, 400);
    }

    const contentLength = Number(request.headers.get('Content-Length') ?? 0);
    if (contentLength > MAX_BODY_BYTES) {
      return json({ ok: false, error: 'Payload too large.' }, 413);
    }

    const body = await request.arrayBuffer();
    if (body.byteLength > MAX_BODY_BYTES) {
      return json({ ok: false, error: 'Payload too large.' }, 413);
    }

    try {
      const telegramResponse = await fetch(
        `https://api.telegram.org/bot${env.TELEGRAM_BOT_TOKEN}/${method}`,
        {
          method: 'POST',
          headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
          },
          body,
          redirect: 'manual',
        },
      );

      return new Response(telegramResponse.body, {
        status: telegramResponse.status,
        headers: {
          'Cache-Control': 'no-store',
          'Content-Type': telegramResponse.headers.get('Content-Type') ?? 'application/json',
          'X-Content-Type-Options': 'nosniff',
        },
      });
    } catch {
      return json({ ok: false, error: 'Telegram upstream is unavailable.' }, 502);
    }
  },
};
