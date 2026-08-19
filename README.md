# LegalStudyBot

PHP/Apache chatbot service for the CUHK LAW course *Hong Kong Constitutional Law*, deployed in the shared VPS Docker Compose stack as `legalstudybot`.

## Architecture

- **Student UI** — `public_html/index.html` + `assets/app.js` + `assets/styles.css`. A dependency-free chat page (light/dark theming, streaming answers, citation chips, quiz mode, copy/email transcript).
- **Model calls** — the browser talks to a Cloudflare Worker (URL configured via `data-worker-url` on `<body>` in `index.html`), which holds the DeepSeek API key and the system prompt.
- **Retrieval** — `data_index.php` serves BM25 results from `index_bm25.json`. The shared tokenizer in `lib/tokenizer.php` handles English words and Chinese character bigrams; the indexer (`admin/reindex.php`) and the query endpoint must always use the same tokenizer.
- **Admin panel** — `/admin/` (session login) for uploading `.pptx/.ppt/.pdf/.txt` course materials, re-indexing, viewing logs, and the usage dashboard (`dashboard.php`).
- **Logging** — `log_client.php` appends anonymous Q&A events to `logs/usage.csv` and `logs/usage.ndjson`. No IP addresses or user agents are stored.
- **Email transcripts** — `email_transcript.php` (PHPMailer over SMTP).

Apache hardening lives in `apache/security.conf` (enabled in the Dockerfile): it denies direct access to `logs/`, `data/`, `uploads/`, `extra/`, `config.php` and `index_bm25.json`, and sets CSP and other security headers. Note the CSP only allows scripts from the site itself plus `connect-src` to the Worker — no inline scripts.

## Configuration

Copy `public_html/config.example.php` to `public_html/config.php` on the server and fill in real values. `config.php` is never committed and is preserved across deploys.

## Deployment

Pushes to `main` run a PHP syntax check, then deploy to `/opt/services/services/legalstudybot` on the VPS and rebuild only the `legalstudybot` Compose service. Pull requests run the syntax check only.

The live server keeps these files and directories outside Git and preserves them during deploys:

- `public_html/config.php`
- `public_html/data/`
- `public_html/uploads/`
- `public_html/logs/`
- `public_html/extra/`
- `public_html/index_bm25.json`

Do not commit production secrets, logs, generated indexes, or uploaded course materials unless you intentionally move those into Git source control.

## After deploying tokenizer or indexer changes

Run **Force full re-index** from the admin panel so `index_bm25.json` is rebuilt with the current tokenizer (required for Chinese-language retrieval to work).
