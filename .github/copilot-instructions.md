# Copilot Instructions for Paperless Workflow

## Project Overview
This is a PHP-based workflow automation system for document processing, tightly integrated with Paperless-ngx. The architecture is modular, with clear separation between configuration, HTTP I/O, database access, extraction logic, workflow state management, and notification handling.

## Key Components
- `public/webhook.php`: Main entry point and router for HTTP requests.
- `bootstrap.php`: Autoloader and environment setup.
- `src/Config.php`: Loads environment/configuration variables.
- `src/Http.php`: Handles JSON input/output and HTTP exceptions.
- `src/Paperless/Client.php`: REST client for Paperless-ngx API.
- `src/DB/Repository.php`: Database access, upsert logic, workflow state persistence.
- `src/Extract/Extractor.php`: Document extraction logic (AI/heuristics).
- `src/Workflow/Service.php`: Workflow state machine, tag/title logic, validation, notification triggers.
- `src/Controller/WebhookController.php`: Main controller for webhook events.
- `bin/wf_poller.php`: CLI poller for batch document processing and workflow automation.

## Developer Workflows
- **Local Development:**
  - Start HTTP server: `php -S 0.0.0.0:8080 -t public`
  - Run poller: `php bin/wf_poller.php [--once|--dry|--debug|--doc=<id>]`
- **Configuration:**
  - Environment variables in `workflow.env` and `config/app.php`.
  - Workflow states/tags in `config/workflow.php`.
- **Database:**
  - Uses PDO for DB access. Upsert logic in `Repository.php`.
- **Notifications:**
  - Uses PHPMailer if available, otherwise logs notifications.
  - Notification templates and actors are configured via DB variables and workflow config.

## Patterns & Conventions
- **Workflow State:**
  - States managed via tags (e.g., `WF:Init`, `WF:Pruefen`, etc.).
  - State transitions and tag updates are atomic (see `patchDocumentAtomic`).
- **Custom Fields:**
  - Mapped via `CF_MAP` in poller and workflow service.
  - Extraction merges AI and user fields, with validation and override logic.
- **Error Handling:**
  - All errors and debug info logged via `Log::j()`.
- **Extensibility:**
  - Add new document types by updating workflow config and prompts.
  - Notification logic is pluggable (MailNotifier/LogNotifier).

## Integration Points
- **Paperless-ngx API:**
  - All document and tag operations via REST client (`PaperlessClient`).
- **Database:**
  - Workflow state and extract data persisted via `Repository`.
- **Notifications:**
  - Email via PHPMailer, fallback to log.

## Examples
- To process a single document: `php bin/wf_poller.php --doc=1234 --debug`
- To run the workflow poller in batch mode: `php bin/wf_poller.php`
- To add a new workflow state, update `config/workflow.php` and ensure tag creation in poller.

---
If any section is unclear or missing, please provide feedback to improve these instructions.