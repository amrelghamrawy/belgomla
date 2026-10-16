# wa-gateway

Holds one WhatsApp Web session (Baileys) per linked store number, so each tenant's OTPs and invitations go from its own number (D70, `tasks/12-tenant-whatsapp.md`). Laravel is the only client and the source of truth. The gateway knows opaque session keys, never tenants.

- Node 22+, no browser, no Docker. In production it runs as the systemd unit `belgomla-wa-gateway`.
- Listens on `127.0.0.1` only. Every route except `/health` needs `Authorization: Bearer $WA_GATEWAY_TOKEN`.
- Events go to `$WA_GATEWAY_WEBHOOK_URL`, signed with `X-Gateway-Signature`: hex HMAC-SHA256 of `{X-Gateway-Timestamp}.{body}` with `$WA_GATEWAY_WEBHOOK_SECRET`.

```
npm ci
npm run typecheck && npm test
npm run build && WA_GATEWAY_TOKEN=dev WA_GATEWAY_WEBHOOK_SECRET=dev npm start
```

| Variable                     | Default           |
| ---------------------------- | ----------------- |
| `WA_GATEWAY_HOST` / `_PORT`  | `127.0.0.1:3100`  |
| `WA_GATEWAY_TOKEN`           | empty, so every request is refused |
| `WA_GATEWAY_WEBHOOK_URL`     | empty, so events are dropped |
| `WA_GATEWAY_WEBHOOK_HOST`    | Host header override, e.g. `hooks.belgomla.com` when posting to 127.0.0.1 |
| `WA_GATEWAY_WEBHOOK_SECRET`  | empty, so Laravel refuses the events |
| `WA_GATEWAY_DATA_DIR`        | `./data/sessions` (credentials, mode 700) |
| `WA_GATEWAY_MAX_SESSIONS`    | `50`              |
| `WA_GATEWAY_SEND_PER_MINUTE` | `10` per number   |
| `WA_GATEWAY_LOG_LEVEL`       | `info`            |

## API

| Method | Path                      | Response                                                        |
| ------ | ------------------------- | --------------------------------------------------------------- |
| GET    | `/health`                 | `{ ok, sessions, open }`                                        |
| GET    | `/sessions`               | `{ live: [key], stored: [key] }`                                |
| POST   | `/sessions/:key`          | `{ state, qr, phone }`; `503 capacity`                          |
| GET    | `/sessions/:key`          | `{ state: connecting\|pairing\|open\|closed, qr: data URL\|null, phone: E.164\|null }`; `404 not_found` |
| DELETE | `/sessions/:key`          | `{ logged_out }`: logs out of the phone (when open), then deletes the credentials |
| POST   | `/sessions/:key/messages` | `{ to: E.164, text }` → `{ id }`; `409 not_open`, `422 not_on_whatsapp`, `429 rate_limited` + `retry_after` seconds, `400 validation_failed` |

Events (`{ event, session_key, data, sent_at }`):

- `gateway.started`
- `session.open { phone }`
- `session.closed { reason, fatal }`, where reason is `qr_expired`, `reconnect_exhausted`, or a Baileys reason such as `logged_out`
- `message.status { id, status: delivered|read|failed }`
