# posada.io — website

Canonical source for the **posada.io** marketing site (static HTML/CSS/JS + a few
PHP API endpoints), including the Lovelace landing subsite under `lovelace/`.

This is the single source of truth. It replaces the copy that previously lived inside
the `bot-bot` repo under `website/`.

## Layout
- `index.html`, `*.html` — pages (trading-bot landing, NoteG privacy/start, token tools, etc.)
- `assets/`, `images/`, `protect.js`, `i18n/` — static assets and locale JSON
- `api/*.php` — server-side endpoints (TapTools-backed token/whale/volume data)
- `lovelace/` — Lovelace product landing pages
- `token/`, `*.html` tools — leaderboard, movers, whales, simulator, backtest

## Hosting & deploy
posada.io is **static hosting on one.com** (Apache). It is **not** GitHub Pages and
**not** served from the bot. Deploy with:

```
./deploy.sh
```

`deploy.sh` mirrors this repo UP to the one.com docroot `webroots/df8d18c6/` over
SFTP, `--only-newer` and **without `--delete`** (so server-only content is preserved).

### one.com gotchas (read before deploying)
- The SFTP endpoint only exists while the **"Allow access SSH & SFTP"** toggle is ON
  in the one.com panel. While OFF, the host is NXDOMAIN.
- **Toggling that switch resets the SSH password.** After any toggle, copy the current
  password from the one.com SSH&SFTP panel into `~/onecom_pw.txt` (never committed).
- After enabling, the host can stay NXDOMAIN for up to ~6 minutes before DNS publishes.
- The host is published IPv6-only; `deploy.sh` pins the resolved address to ride out
  DNS flapping.

## NOT in this repo (server-only, intentionally gitignored)
- `api/config.php`, `**/config.php` — hold the TapTools / Blockfrost keys
- `license/` — the license-server PHP backend (source: `bot-bot/license-server/`)
- `data/`, `releases/`, `apk/`, `lovelace/download/data/` — caches, binaries, customer license data
