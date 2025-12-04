<?php

namespace App\Controller;

use App\Config;
use App\Paperless\Client as PaperlessClient;
use App\Workflow\DoctypeService;
use App\DB\Repository;
use App\Workflow\Service as WF;
use App\Notify\MailNotifier;
use App\Notify\LogNotifier;
use App\Sepa\SimpleSepa;
use App\Workflow\StateTags;

final class ApiController
{
    private PaperlessClient $paperless;
    private DoctypeService  $doctypes;

    public function __construct(private Config $cfg)
    {
        $this->paperless = new PaperlessClient($cfg);
        $this->doctypes  = new DoctypeService(
            $this->paperless,
            $cfg->doctypesCacheFile,
            $cfg->doctypesTtl
        );
    }

    /** GET /api/v1/meta/doctypes */
    public function metaDoctypes(): array
    {
        return [
            'items' => $this->doctypes->listForFrontend(), // [{id: <slug>, label: <name>}...]
            'ttl'   => $this->cfg->doctypesTtl,
        ];
    }


    // src/Controller/ApiV1Controller.php
    public function setDoctype(int $docId, array $body): array
    {
        $raw = (string)($body['doctype'] ?? '');
        if ($raw === '') return ['ok' => false, 'error' => 'Missing doctype'];

        // numerisch? dann direkt als Paperless-ID verwenden
        if (ctype_digit($raw)) {
            $paperlessId = (int)$raw;
        } else {
            // slug -> id
            $paperlessId = $this->doctypes->resolveIdBySlug(strtolower($raw));
            if (!$paperlessId) return ['ok' => false, 'error' => "Unknown doctype slug: $raw"];
        }

        // Patch bei Paperless
        error_log("PATCH doctype translated by slug: " . $paperlessId);
        $this->paperless->patchDocumentType($docId, $paperlessId);
        return ['ok' => true, 'doctype' => $raw, 'paperless_type_id' => $paperlessId];
    }

    public function commit(int $docId, array $body): array
    {
        $status = (string)($body['status'] ?? '');
        $usr    = (array)  ($body['user_fields'] ?? []);
        $title = (string)($body['title'] ?? null);
        $notiz = (string)($body['notes'] ?? null);

        // ❷ Mapping: Doctype-Label -> Doctype-ID (Paperless)
        $doctypeId = null;
        if (!empty($body['document_type'])) {
            $doctypeId = $this->paperless->findTagIdByName((string)$body['document_type']) // z.B. "Rechnung" → 7
                ??  $this->doctypes->resolveIdBySlug((string)$body['document_type'])   // fallback
                ?? null;
        }

        \App\Log::j('INFO', 'commit', ['map' => $body]);
        if ($status === '') return ['ok' => false, 'error' => 'status missing'];

        // 1) Status → Paperless-Tag-ID
        \App\Log::j('INFO', 'status with comit', ['map' => $status]);
        // ======================= translate - very static -> must improve later
        // Key → Name
        $plstatus = StateTags::keyToName($status); // "WF:Rechnungsfreigabe_erforderlich"

        if ($plstatus === null) {
            return ['ok' => false, 'error' => "unknown status $status"];
        }
        // ====================================================================

        $tagIdint = $this->paperless->findTagIdByName($plstatus); // z. B. INIT→123, APP_REQ→124 ...
        if (!$tagIdint) return ['ok' => false, 'error' => "unknown status $status"];
        $tagId = array('Tags' => $tagIdint);
        // 2) Benutzerfelder → Paperless Custom-Field-Payload
        //$cfPayload = $this->fieldMap->toPaperless($usr);   // kennt Field-IDs, Selektionswerte etc.
        $cfg  = new Config();
        $pl   = new PaperlessClient($cfg);
        $repo = new Repository($cfg);
        $ntf = class_exists(\PHPMailer\PHPMailer\PHPMailer::class)
            ? MailNotifier::fromEnv()
            : new LogNotifier();
        $sepa = new SimpleSepa($repo);
        $wf   = new WF($pl, $repo, $ntf, $sepa);

        $patches = $wf->syncCustomFieldsAndValidate($docId, $usr, $status, $pl);
        $cfPatches = [];
        foreach ($patches /* dein Dict */ as $fid => $val) {
            // Typ-Sauberkeit: --> läuft auch ohne die nachfolgende Prüfung. Aber hart-codiert geht überhaupt nicht.
            //if ((int)$fid === 15) { // falls 15 = Betrag (decimal)
            //    $val = number_format((float)$val, 2, '.', ''); // "7676.00"
            //}
            $cfPatches[] = ['field' => (int)$fid, 'value' => $val];
        }

        $code = 0;
        $body = '';

        //Log::j('DEBUG', 'AtomicPatchBeforeCall', ['Title' => $newTitle, 'Tags' => $finalTagIds, 'Patches' => $cfPatches]);
        $ok = $pl->patchDocumentAtomic(
            $docId,
            $title,        // null, wenn du den Titel nicht ändern willst
            $tagId,      // null, wenn Tags unverändert bleiben sollen
            $cfPatches,        // null, wenn keine CF-Updates anstehen
            $code,
            $body,
            $doctypeId,    // null, wenn Doctype unverändert bleiben soll
            $notiz
        );
        if ($ok === true) {
            return ['ok' => true, 'Status' => 'paperless patched'];
        } else {
            return ['ok' => false, 'Status' => 'paperless patch failed'];
        }
    }

    // App\Api (Fassade)
    public function setTitle(int $docId, string $apititle): array
    {
        $cfg  = new Config();
        $pl   = new PaperlessClient($cfg);
        $code = 0;
        $body = '';
        $title = $apititle;
        $tagId = null;
        $cfPatches = null;
        //Log::j('DEBUG', 'AtomicPatchBeforeCall', ['Title' => $newTitle, 'Tags' => $finalTagIds, 'Patches' => $cfPatches]);
        $ok = $pl->patchDocumentAtomic(
            $docId,
            $title,        // null, wenn du den Titel nicht ändern willst
            $tagId,      // null, wenn Tags unverändert bleiben sollen
            $cfPatches,        // null, wenn keine CF-Updates anstehen
            $code,
            $body
        );
        if ($ok === true) {
            return ['ok' => true, 'Status' => 'paperless patched'];
        } else {
            return ['ok' => false, 'Status' => 'paperless patch failed'];
        }
    }
}
