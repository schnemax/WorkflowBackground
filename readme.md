# Paperless Workflow (refactored)

## Struktur
- `public/webhook.php` — Entry (Router)
- `bootstrap.php` — Autoloader
- `src/Config.php` — Env
- `src/Http.php` — JSON I/O
- `src/Paperless/Client.php` — REST Client
- `src/DB/Repository.php` — PDO + Upsert
- `src/Extract/Extractor.php` — Extraktion
- `src/Workflow/Service.php` — Typ/Validation/Tags/Titel
- `src/Controller/WebhookController.php` — Controller

## Start (lokal)
```bash
php -S 0.0.0.0:8080 -t public
