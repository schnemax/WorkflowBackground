# Paperless Workflow (refactored)
This component - here called as "workflow poller" - is intended to scan periodically through paperless documents and selects them based on defined tag ids. Then - according to the tag id - it takes various actions in order to push documents through the workflow process. 
Primary focus is on invoices, which are treated in a manner to check them for completness of attritubes (e.g. invoice number, amount, issuer iban, etc.), which are required to finally create a SEPA.001 file, which then can be used to feed the bank transaction. On the way to the final statement, invoices have to pass an approval process. The poller here is accompanied by a workflow applicaton where the various actors (staff, approver, ..) carry out there actions against the invoice. 
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
