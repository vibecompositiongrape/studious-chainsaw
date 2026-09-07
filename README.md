# LegalStudyBot

PHP/Apache chatbot service for the CUHK LAW course *Hong Kong Constitutional Law*, deployed in the shared VPS Docker Compose stack as `legalstudybot`.

## Architecture

- **Student UI** — `public_html/index.html` + `assets/app.js` + `assets/styles.css`. A dependency-free chat page (light/dark theming, streaming answers, citation chips, quiz mode, copy/email transcript).
- **Model calls** — the browser talks to a Cloudflare Worker (URL configured via `data-worker-url` on `<body>` in `index.html`), which holds the DeepSeek API key and the system prompt. Its source is now in `cloudflare/worker.js`; see `cloudflare/README.md` for its separate deployment procedure.
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

## Chat response tests

Run `node --test tests/*.test.cjs` with Node 22 or later. No npm dependencies are required.
The tests cover response parsing, service errors, incomplete answers, rendering cleanup,
and exclusion of failed turns from subsequent model requests.

## Empty-response investigation (7 September 2026)

Direct HTTP requests reproduced the student screenshot without using a browser.
The live JavaScript matched this repository. Retrieval returned course-material
chunks successfully for the diagnostic questions.

- A Basic Law question returned a complete answer, both without and with retrieved context.
- The exact self-check quiz prompt returned HTTP 200 with `text/event-stream`,
  keep-alive comments, and `[DONE]`, but no answer data. A repeat also failed.
- A fresh `teach me about proportionality` request with retrieved context also
  returned no answer data, without any preceding empty assistant message.
- Sending `stream: false` still produced SSE. A quiz succeeded once, but the
  repeated quiz and proportionality request failed, so this is not a reliable workaround.

This establishes that the Worker sometimes finishes without emitting answer text;
it does not by itself establish why. The subsequently supplied Worker source
revealed a 1,300-token reasoning-model cap and discarded finish reasons, consistent
with reasoning exhausting the allowance before an answer begins. The source also
hard-codes streaming, so the client's stream flag had no effect on those tests.

The replacement in `cloudflare/worker.js` increases the allowance, uses the current
explicit model identifier, and preserves failure diagnostics. Frontend changes
display service failures and exclude failed turns from subsequent conversation
history. The Worker must be deployed separately in Cloudflare; a VPS deployment
alone will not apply the generation fix. See `cloudflare/README.md` for evidence,
limitations and validation instructions.
