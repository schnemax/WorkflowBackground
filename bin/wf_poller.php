#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../vendor/autoload.php';

use App\Config;
use App\Log;
use App\Paperless\Client as PaperlessClient;
use App\DB\Repository;
use App\Extract\Extractor;
use App\Workflow\Service as WF;
use App\Notify\Notifier;
use App\Notify\MailNotifier;
use App\Notify\LogNotifier;
use App\Sepa\SimpleSepa;
use App\Http;
use App\Workflow\StateTags;

App\Env::loadFromFile(getenv('WORKER_ENV_FILE') ?: __DIR__ . '/../workflow.env');

Log::init(); // liest optional LOG_LEVEL

$APP_ROOT = rtrim(getenv('APP_ROOT') ?: '/var/ppl/app', '/');
$VAR_DIR  = $APP_ROOT . '/var';
@is_dir($VAR_DIR) || @mkdir($VAR_DIR, 0775, true);

// Stop/Reload-Dateien (konfigurierbar, aber Defaults reichen)
$STOP   = getenv('WF_STOP_FILE')   ?: "$VAR_DIR/wf.stop";
$RELOAD = getenv('WF_RELOAD_FILE') ?: "$VAR_DIR/wf.reload";
$SLEEP_US = (int)(getenv('WF_SLEEP_US') ?: 2_000_000); // 2s

// Paperless-Ziele (Host, da Poller außerhalb Docker läuft)
$BASE  = rtrim(getenv('PAPERLESS_URL') ?: 'http://127.0.0.1:8010', '/');
$TOKEN = getenv('PAPERLESS_TOKEN') ?: '';

//Log::j('INFO', 'envvars', ['approot' => $APP_ROOT, 'vardir' => $VAR_DIR, 'base' => $BASE, 'token' => $TOKEN]);

$ntf = class_exists(\PHPMailer\PHPMailer\PHPMailer::class)
    ? MailNotifier::fromEnv()
    : new LogNotifier();




// ---------------------------- Boot ----------------------------

$cfg  = new Config();
$pl   = new PaperlessClient($cfg);
$repo = new Repository($cfg);
$ext  = new Extractor();
$sepa = new SimpleSepa($repo);
$wf   = new WF($pl, $repo, $ntf, $sepa);
//$ntf  = new Notifier();
Log::$level = 'DEBUG';
// --------------------------- Self Checks ----------------------------------------
// ---------- SELF-CHECK ----------
[$ok, $problems] = selfCheck($BASE, $TOKEN);
if ($ok) {
    Log::j('INFO', 'selfcheck_ok', ['base' => $BASE]);
} else {
    Log::j('ERROR', 'selfcheck_fail', ['base' => $BASE, 'issues' => $problems]);
    // Safe-Mode: wir starten trotzdem und versuchen periodisch erneut
}

// ---------- BOOT (Tags vorbereiten, aber nicht zwingend) ----------
$STATE = \App\Workflow\StateTags::MAP ?? [];        // ['INIT'=>'WF:Init', ...]
$TAGCACHE = $VAR_DIR . '/tagmap.json';
$TAGS = bootstrapTagMap($STATE, $BASE, $TOKEN, $TAGCACHE);

$S = $STATE;

// IDs pro Key (robust, weil nicht lokalisiert)
$T = [];
foreach ($S as $key => $name) {
    $T[$key] = $wf->ensureTag($name); // legt Tag an (falls nötig) und gibt ID zurück
}

// ---------------------------- CLI-Options ----------------------------
$opts = ['once' => false, 'doc' => null, 'dry' => false, 'debug' => false];
foreach ($argv ?? [] as $a) {
    if ($a === '--once') $opts['once'] = true;
    elseif ($a === '--dry') $opts['dry'] = true;
    elseif ($a === '--debug') {
        $opts['debug'] = true;
        Log::$level = 'DEBUG';
    } elseif (preg_match('/^--doc=(\d+)$/', $a, $m)) $opts['doc'] = (int)$m[1];
}

// ---------------------------- Main ----------------------------
if (!is_dir('/app/var')) @mkdir('/app/var', 0775, true);
@file_put_contents('/app/var/wf_last_run.txt', date('c'));
//\App\Log::j('INFO', 'Start poller', ['since' => $since]);

// für Einzeltests den Poller mit der Option doc= aufrufen
if (!empty($opts['doc'])) {
    process_one((int)$opts['doc'], $opts, $cfg, $pl, $repo, $ext, $wf, $ntf, $T, $S);
    exit(0);
}

// für Testzwecke den Poller so aufrufen, dass er alle Dokumente einsammelt -> diese
// abarbeitet und dann abschliesst. Im Echtbetrieb wird das als interner Endlosbetrieb
// gestartet. Nach jedem erfolgten Aufruf wird dann "geschlafen".

$retryAt = time();        // für periodische Re-Checks

while (true) {
    if (is_file($STOP)) {
        Log::j('INFO', 'stop_file_detected', ['file' => $STOP]);
        @unlink($STOP);
        break; // sauber beenden
    }

    // RELOAD (z. B. ENV neu laden, Tag-Map neu)
    if (is_file($RELOAD)) {
        Log::j('INFO', 'reload_file_detected', ['file' => $RELOAD]);
        @unlink($RELOAD);
        \App\Env::loadFromFile(getenv('WORKER_ENV_FILE') ?: __DIR__ . '/../workflow.env');
        // Base/Token ggf. neu setzen:
        $BASE  = rtrim(getenv('PAPERLESS_URL') ?: 'http://127.0.0.1:8010', '/');
        $TOKEN = getenv('PAPERLESS_API_TOKEN') ?: '';
        $TAGS  = bootstrapTagMap($STATE, $BASE, $TOKEN, $TAGCACHE);
    }

    // Periodischer Self-Check/Retry (alle 60s)
    //if (time() >= $retryAt) {
    //    [$ok, $problems] = selfCheck($BASE, $TOKEN);
    //    if (!$ok) {
    //        Log::j('WARN', 'selfcheck_retry_fail', ['issues' => $problems]);
    //        $retryAt = time() + 60;
    //    } else {
    //        $retryAt = time() + 300; // 5 Minuten, wenn stabil
    //    }
    //}

    try {
        run_once_batch($opts, $pl, $repo, $ext, $wf, $ntf, $T, $S);
        if ($opts['once']) break;
        // kurze Pause, CPU schonen
        sleep(120);
    } catch (Throwable $e) {
        Log::j('WARN', 'http_client_reset_fail', ['error' => $e->getMessage()]);
        break; // exit until we're stable
    }
}


// ==================================================================================
// ---------------------------- Core: einzelnes Dokument ----------------------------
// diese Logik wird aus dem Batch-Lauf für jedes dort selektierte Dokument aufgerufen
// ==================================================================================
function process_one(
    int $docId,
    array $opts,
    Config $cfg,
    PaperlessClient $pl,
    Repository $repo,
    Extractor $ext,
    WF $wf,
    Notifier $ntf,
    array $T,
    array $S
): void {
    Log::j('INFO', 'process_one.start', ['doc' => $docId, 'dry' => $opts['dry']]);

    // Dokument & Tags holen

    $doc = $pl->getDocumentExpanded($docId, ['expand' => 'document_type']);

    //Log::j('DEBUG', 'process_one->doc', ['doc' => $doc]);
    if (!$doc || empty($doc['id'])) {
        Log::j('ERROR', 'doc.missing', ['doc' => $docId]);
        return;
    }
    // ===============  prepare general variables =====================
    // Content laden (mit Backoff)
    //$notiz = '';
    [$docFull, $content] = backoffFetchContent($pl, $docId, 90.0);

    // die aktuellen Tags (e.g. WF:PRUEFEN, etc.) werden im array currentTagIds verfügbar gemacht
    $currentTagIds   = array_map('intval', $doc['tags'] ?? []);

    // gewünschten State aus Tags lesen (Benutzereingriff)current_state_key
    // $ALLOWED enthält alle gültigen State-IDs (intern normalisiert - nicht der Tag-Ausdruck in paperless)
    $wfCfg   = require __DIR__ . '/../config/workflow.php';
    $ALLOWED = $wfCfg['ALLOWED'];      // wie zuvor definiert


    // gemeinsame Vorbereitungen -> gemeinsame Variablen in der nachfolgenden
    // Verarbeitung
    $href   = $pl->documentUrl($docId);   //  geforderte URL-Variable  (zeigt auf Workflow Application)

    $stateIds  = array_filter([
        $T['INIT'] ?? null,
        $T['PRUEFEN'] ?? null,
        $T['PRUEFEN2'] ?? null,
        $T['UNVOLL'] ?? null,
        $T['APP_REQ'] ?? null,
        $T['APP_OK'] ?? null,
        $T['APP_REJ'] ?? null,
        $T['SEPA'] ?? null,
        $T['CLOSE'] ?? null,
        $T['ERROR'] ?? null,
        $T['IGNORE'] ?? null,
        $T['REACT'] ?? null,
    ]);


    // Konfiguration: Mapping deiner CF-Namen
    // die CF_MAP muss Dokumentenspezifisch definiert werden. Die aktuelle Map ist korrekt
    // für den Dokumententyp = "Rechnung" oder es wird die Map so erweitert, dass sie alle
    // möglichen Extraktionsfelder umfasst (= pragmatischer Ansatz). 

    // wie die Felder in Dokumententypen ungleich "Rechnung" belegt sind, kann aus 
    // den Prompts der Dokumententypen entnommen werden, sofern für einen Dokumententyp
    // ein spezieller Prompt vorhanden ist -> die Prompts sind in der Albatros-DB als
    // Systemvariable hinterlegt

    $CF_MAP = [
        'issuer_name'     => 'Rechnungssteller',
        'invoice_number'  => 'Rechnungsnummer',
        'invoice_date'    => 'Rechnungsdatum',
        'invoice_amount'  => 'BetragBrutto',
        'issuer_iban'     => 'GlaeubigerIBAN',
        'issuer_bic'      => 'GlaeubigerBIC',
        'payment_purpose' => 'Verwendungszweck',
        'direct_debit'    => 'Zahlart', // select mit Labels „Einzug“ / „Überweisung“
        'konto'           => 'Ueberweisungskonto',
        'note'            => 'Notizen',
    ];

    // Dokumenttyp (Webhook/Regel hat Vorrang, Heuristik Fallback)
    // die gesamte Logik ist aktuell nur für Rechnungen vorgesehen. Deshalb die Prüfung
    // auf den Dokumententyp gleich vorneweg
    //$declaredType = $docFull['document_type__name'] ?? null;
    $declaredType = $docFull['document_type'] ?? null;
    if ($declaredType === null) {
        $typeName = 'Unbekannt';
        $declaredType = getenv('DOCTYPEIDUNBEKANNT') ?: '01'; // ID für Unbekannt -> Achtung: 01 ist irgendwas anderes
    } else {
        $typeName = $pl->getDocumentTypeName($declaredType);
    }
    Log::j('DEBUG', 'getDocumentTypeName', ['type' => $typeName, 'declaredType' => $declaredType]);

    // festhalten, ob wf_job-eintrag für dieses Dokument bereits besteht -> wird benötigt um
    // zu entscheiden, ob bei neuen Dokumenten eine Information gesendet wird

    $wfjob_exists = $repo->wfGetState($docId);

    // die aktuellen TagIDs werden nun in internen State umgewandelt
    $currentState = $wf->enforceAndResolveState($docId, $T, $ALLOWED, $currentTagIds, '', $typeName);
    // $state ist jetzt bereinigt & eindeutig, z. B. 'APP_REQ'
    Log::j('DEBUG', 'currentStat', ['state is' => $currentState]);

    $notiz = $wf->normalize_note($doc['notes'] ?? $doc['note'] ?? '');
    Log::j('DEBUG', 'Notizen', ['notes' => $notiz]);

    //  ============================ ausklammern der weiteren Verarbeitung ================================
    //  sofern es keine Rechnung ist -> keine Prüfung auf den Status oder dergleichen

    switch ($typeName) {

        // diese Dokumenttype werden einer weitergehenden Verarbeitung unterzogen
        case 'Rechnung':
        case 'Sachspende':
        case 'Ersatzbeleg':
        case 'Barbeleg':
        case 'Kontoauszug':
        case 'LG_Abrechnung':
        case 'LG_Lohnsteuer':
        case 'LG_KK':
        case 'Bescheid':
        case 'Unbekannt':
            break;

        // keiner der vorgenannten Dokumententypen -> Workflow wird geschlossen
        default:
            $typeName = 'Unbekannt';
            //Log::j('INFO', 'skip.not-relevant', ['doc' => $docId, 'type' => $typeName]);
            // ==> ============= hier braucht es noch eine Nachricht - ist defacto ein Fehler
            if (!$opts['dry']) {
                $tplBodyRaw = $repo->get_variable_value('WF:irrelevant') ?? '';
                $tplBody    = $wf->normalize_template($tplBodyRaw); // <<< WICHTIG

                $missing = [];

                $vars = [
                    'DOK_ID'       => $docId,
                    'MISSING_LIST' => $wf->build_missing_list($missing),
                    'NOTIZ'        => $notiz,
                    'URL'          => $href,                                  // für href und sichtbaren Text nutzbar
                    'URL_TEXT'     => htmlspecialchars($href, ENT_NOQUOTES),  // falls du {{URL_TEXT}} im sichtbaren Teil nutzen willst
                ];
                $body = trim($tplBody) !== '' ? $wf->render_template($tplBody, $vars)
                    : "<p>unbekannter Dokumententyp " . $typeName . " - keine Workflow-Bearbeitung<a href=\"{$href}\">öffnen</a></p>";
                $subject = "unbekannter Dokumententyp - keine Workflow-Bearbeitung: Dok #{$docId}";
                $ntf->send(
                    $repo->get_variable_value('WF_DEFAULT_ACTOR') ?: 'it_admin@albatros-hospiz.de',
                    $subject,
                    $body
                );
                // dieser Fall muss hier nun abgeschlossen werden -> es wird kein dms_extract bzw.
                // wf_job geschrieben werden
                $nextState = 'CLOSE';
                $targetKey = $nextState; // z.B. 'APP_REQ' oder 'UNVOLL' …
                $targetId  = (int)($T[$targetKey] ?? 0);

                // build wfhistory entry
                $repo->logWfHistory($docId, $currentState, $nextState, '-intern-');

                $finalTagIds = $wf->buildFinalTags($currentTagIds, $stateIds, $targetId);
                // wir schreiben keinen neuen Titel
                $newTitle = '';
                // es gibt auch keine Benutzerfeld-Patches
                $cfPatches = null;
                // nur die TagID setzen
                $code = 0;
                $body = '';
                Log::j('DEBUG', 'AtomicPatchBeforeCall', ['Title' => $newTitle, 'Tags' => $finalTagIds, 'Patches' => $cfPatches]);
                $ok = $pl->patchDocumentAtomic(
                    $docId,
                    $newTitle,        // null, wenn du den Titel nicht ändern willst
                    $finalTagIds,      // null, wenn Tags unverändert bleiben sollen
                    $cfPatches,        // null, wenn keine CF-Updates anstehen
                    $code,
                    $body
                );
                if (!$ok) {
                    Log::j('DEBUG', 'AtomicPatch', ['ok' => $ok, 'Title' => $newTitle, 'Tags' => $finalTagIds, 'Patches' => $cfPatches]);
                }
                $repo->wfSetState($docId, $nextState, $newTitle, $typeName);
                Log::j('INFO', 'process_one.done', ['doc' => $docId ?? '0', 'exit_stop' => "keine Rechnung"]);
                return;
            }
    }

    // =====================================================================================
    // ab hier haben wir es nur mit relevanten Dokumententypen zu tun. Die weitere
    // Bearbeitung wird durch den Status entschieden. Bezogen auf den Status gibt 
    // es aber wiederum Unterschiede durch den Dokumententypen, da diverse Stati 
    // z.B. nur für eine Rechnung relevant sind
    // =====================================================================================

    //abhängig vom aktuellen Status müssen die unterschiedlichen Aktionen ausgeführt werden
    switch ($currentState) {

        // =============================================================================================
        // wir ziehen hier den Status PRUEFEN vorneweg um die Dokumente zu selektieren, welche via
        // dem Workflow zur Prüfung "weitergereicht" werden. Aber nur für den Dokumententype "Rechnung"
        // sind nachfolgende Prüfungen für den Zahlungsverkehr relevant, weshalb hier für "Nicht-Rechnungen"
        // die Workflow-Verarbeitung abgeschlossen wird
        // =============================================================================================

        case "PRUEFEN":
        case "INIT":
            if ($wfjob_exists !== null && $currentState === 'INIT') {   // so wird INIT-Verarbeitung nur einmal Initial ausgeführt
                break;
            }
            Log::j('DEBUG', 'Enter into PRUEFEN/INIT', ['doctypename' => $typeName]);

            // wenn es sich nicht um eine Rechnung handelt, und es soll geprüft werden dann wird der Workflow hier ohne Nachricht
            // zum Benutzer abgeschlossen (CLOSE) -> für Dokumenttypen anders als Rechnung ist der Zahlungsverkehr-Workflow
            // irrelevant. Der Benutzer bekommt eine Information, wenn ein Dokument geladen wurde (INIT). er/sie kann das
            // Dokument ansehen, Details darin ändern und dann den Workflow anweisen zu prüfen. Diese Prüfung führt dann
            // aber nur zu einem CLOSE ohne weitere Prüfung (zumindest für den aktuellen Stand ist das so gewünscht)

            if ($typeName !== 'Rechnung' && $currentState === 'PRUEFEN') {
                // für Barbelege müssen wir jetzt noch versuchen, einen Rückbezug des Dokumentes zu dem Barbeleg
                // herzustellen. Der Extrakt beinhaltet - wenn alles korrekt läuft - die Referenz zum Barbeleg in
                // der Form bbbb:iiii wobei bbbb=Kassenbuchid und iiii=Kassenbucheintrag ist
                // preg_match('/^(\d+):(\d+)$/', $ex['invoice_number'] ?? '', $m)

                $nextState = 'CLOSE';
                $code = 0;
                $body = '';
                $newTitle = null;
                $cfPatches = null;

                // notwendige Schritte
                // 1 - prüfen, ob $ex('invoice_number') eine gültige Referenz enthält
                $exKI = $ext->extract($content, $typeName);
                $exCF = $wf->collectExFromCF($docId, $CF_MAP, $pl, $repo);  // liefert Keys wie oben
                $ex = $wf->mergeEx($exKI, $exCF);    // <-- ab hier NUR NOCH $ex verwenden
                $patches = $wf->syncCustomFieldsAndValidate($docId, $ex, $nextState, $pl);
                $cfPatches = [];
                foreach ($patches /* dein Dict */ as $fid => $val) {
                    // Typ-Sauberkeit:
                    if ((int)$fid === 15) { // falls 15 = Betrag (decimal)
                        $val = number_format((float)$val, 2, '.', ''); // "7676.00"
                    }
                    $cfPatches[] = ['field' => (int)$fid, 'value' => $val];
                }
                if ($typeName === 'Barbeleg') {
                    $newTitle = ('Kassenbuch: ' . $ex['invoice_number']) ?? 'Barbeleg ' . ' ' . ($ex['payment_purpose'] ?? '');
                    preg_match('/^(\d+):(\d+)$/', $ex['invoice_number'] ?? '', $m);
                    if (count($m) === 3) {
                        $dkbid = (int)$m[1];
                        $lfdnr = (int)$m[2];
                        $ref = $repo->find_dkbd_entry($dkbid, $lfdnr);
                        if ($ref !== null) {
                            $repo->link_dok_to_dkbd($docId, $dkbid, $lfdnr);
                            Log::j('INFO', 'Barbeleg linked', ['doc' => $docId, 'dkbid' => $dkbid, 'lfdnr' => $lfdnr, 'dmsdokid' => $ref]);
                        } else {
                            Log::j('WARN', 'Barbeleg link failed - reference not found', ['doc' => $docId, 'dkbid' => $dkbid, 'lfdnr' => $lfdnr]);
                        }
                    } else {
                        Log::j('WARN', 'Barbeleg link failed - invalid reference', ['doc' => $docId, 'referenz' => $ex['invoice_number'] ?? '']);
                    }
                }
                // build wfhistory entry
                $repo->logWfHistory($docId, $currentState, $nextState, '-intern-');

                // Status-Ziel & IDs
                $targetKey = $nextState;
                $targetId  = (int)($T[$targetKey] ?? 0);
                // 2) Finale Tagliste berechnen (exklusiver State)
                $finalTagIds = $wf->buildFinalTags($currentTagIds, $stateIds, $targetId);
                // es wird wie für Rechnung auch
                $ok = $pl->patchDocumentAtomic(
                    $docId,
                    $newTitle,        // null, wenn du den Titel nicht ändern willst
                    $finalTagIds,      // null, wenn Tags unverändert bleiben sollen
                    $cfPatches,        // null, wenn keine CF-Updates anstehen
                    $code,
                    $body
                );
                $repo->wfSetState($docId, $nextState, $newTitle, $typeName);
                $repo->upsertExtract($docId, $ex, $exKI);
                break;
            }

            // =====================================================================================================

            // Ab hier haben wir es nur mit dem Dokumententyp "Rechnung" zu tun. Wir benötigen
            // die restlichen Prüfungen, um den Zahlungsverkehr abzusichern

            // Extraktion und zusammenführen von Werten ist relevant für die Stati PRUEFEN und INIT

            Log::j('DEBUG', 'Enter2 into PRUEFEN', ['doctypename' => $typeName]);
            // 1) KI-Extrakt  --> hier braucht es jetzt noch der Erweiterung, damit bezogen auf den
            // Dokumenttype unterschiedliche Prompts an die KI gegeben werden, damit diese die
            // Dokumente spezifisch extrahieren kann

            $exKI = $ext->extract($content, $typeName);
            Log::j('DEBUG', 'ex.KI', ['ex' => $exKI]);

            // … im Ablauf, wenn der User erneut auf „WF:Pruefen“ setzt (oder der Poller das Dokument mit PRUEFEN findet):
            $exCF = $wf->collectExFromCF($docId, $CF_MAP, $pl, $repo);  // liefert Keys wie oben
            Log::j('DEBUG', 'ex.CF', ['ex' => $exCF]);

            // Unterschiede CF vs. KI
            $overrides = $wf->computeOverrides($exKI, $exCF);
            Log::j('DEBUG', 'overrides', ['overides' => $overrides]);

            // 3) Merge (CF > KI)
            $ex = $wf->mergeEx($exKI, $exCF);    // <-- ab hier NUR NOCH $ex verwenden
            Log::j('DEBUG', 'ex.merged', ['ex' => $ex]);

            $missing = $wf->validateCF($ex, $repo);
            Log::j('DEBUG', 'missing after merge', ['missing' => $missing]);
            // beide Varianten bereitstellen:
            $missingHtml = $wf->build_missing_list_html($missing, $CF_MAP);
            $missingText = $wf->build_missing_list_text($missing, $CF_MAP);
            //$notiz = $wf->normalize_note($doc['notes'] ?? $doc['note'] ?? '');

            // wenn wir noch in der initialen Phase des Workflows sind, dann belassen wir nun
            // den Status = INIT. Dadurch wird zwar der Hintergrundprozess dieses Dokument immmer
            // wieder lesen, solange, bis der Benutzer sich diesem Dokument angenommen hat und 
            // den Status ändert (entweder -> WF:Pruefen oder er entfernt den Status komplett,
            // weil u.U. dieses Dokument nicht relevant für die weitere Verarbeitung ist)

            if ($currentState === "INIT") {

                if ($wfjob_exists === null) {
                    $wf->common_send(
                        $docId,
                        $doc,
                        'WF:neues_dokument',
                        'WF_DEFAULT_ACTOR',
                        'Neues Dokument - bitte pruefen',
                        $missing,
                        $overrides,
                        $href,
                        $wf,
                        $repo,
                        $ntf
                    );
                }
                $nextState = "INIT"; // Status bleibt bestehen und muss vom Personal händisch gesetzt werden

                // hier geht es weiter wenn wir nicht im initialen Status sind

            } else {

                // Entscheidung ausschließlich anhand CF:
                if ($missing) {
                    // → UNVOLL + Mail
                    $vars = [
                        'DOK_ID'       => $docId,
                        'MISSING_LIST' => $missingText,
                        'MISSING_LIST_HTML' => $missingHtml,
                        'NOTIZ'        => $notiz,
                        'URL'          => $href,
                        'URL_TEXT'     => htmlspecialchars($href, ENT_NOQUOTES, 'UTF-8'),
                    ];
                    if (!$opts['dry']) {

                        $wf->common_send(
                            $docId,
                            $doc,
                            'WF:unvollstaendige_daten',
                            'WF_DEFAULT_ACTOR',
                            'Daten unvollstaendig (Benutzerfelder)',
                            $missing,
                            $overrides,
                            $href,
                            $wf,
                            $repo,
                            $ntf
                        );
                        $nextState = "UNVOLL";
                    }
                } else {
                    // vollständig: je nach Zahlart weiter
                    $nextState = !empty($ex['direct_debit']) ? 'CLOSE' : 'APP_REQ';
                    if (!$opts['dry']) {
                        if ($nextState === 'APP_REQ') {
                            Log::j('DEBUG', 'going to send invoice approval request', ['doc' => $docId]);
                            $wf->common_send(
                                $docId,
                                $doc,
                                'WF:rechnungsfreigabe_erforderlich',
                                'WF_DEFAULT_APPROVER',
                                'Rechnungsfreigabe erforderlich',
                                $missing,
                                $overrides,
                                $href,
                                $wf,
                                $repo,
                                $ntf
                            );
                        } else {
                            // es handelt sich um Rechnung mit Einzug (dd -> direct debit -> Einzug)
                            // Nachricht an den ACTOR, dass weitere Verarbeitung für dieses Dokument
                            // nun geschlossen ist

                            $wf->common_send(
                                $docId,
                                $doc,
                                'WF:dokument_geschlossen',
                                'WF_DEFAULT_ACTOR',
                                'Interne Verarbeitung abgeschlossen',
                                $missing,
                                $overrides,
                                $href,
                                $wf,
                                $repo,
                                $ntf
                            );
                        }
                    }
                }
            }

            // (optional) dms_extract mit CF synchronisieren, damit DB konsistent bleibt:
            // hier geht es gemeinsamt weiter - egal ob Einzug oder Überweigung -> der dms_extract
            // wird geschrieben
            $repo->upsertExtract($docId, $ex, $exKI);

            // aufbereiten aller Updates (Titel, Tags, Benutzerfelder) für das Dokument
            $issuer = $ex['issuer_name'];
            $invDate = $ex['invoice_date'];
            $type = $typeName; // schon bestimm
            switch ($type) {
                case 'Ersatzbeleg':
                    $newTitle = $ex['invoice_number'] ?? 'Ersatzbeleg';
                    break;
                default:
                    $newTitle = $wf->buildTitle($type, $issuer, $exCF, $invDate);
                    break;
            }

            // Status-Ziel & IDs
            $targetKey = $nextState; // z.B. 'APP_REQ' oder 'UNVOLL' …
            $targetId  = (int)($T[$targetKey] ?? 0);

            // build wfhistory entry
            $repo->logWfHistory($docId, $currentState, $nextState, '-intern-');

            // 1) Aktuelle Tags holen (IDs) --> hier mal ignorieren, denn wir haben dies
            // ja schon eingelesen -> siehe vorstehenden Code
            //$doc = $pl->getDocument($docId, []);  // Detail reicht
            //$currentTagIds = array_map('intval', $doc['tags'] ?? []);

            // 2) Finale Tagliste berechnen (exklusiver State)
            $finalTagIds = $wf->buildFinalTags($currentTagIds, $stateIds, $targetId);

            // 3) Custom Fields *einmal* bauen (Name→ID→value bereits erledigt)
            //$cfPatches = []; // Liste von ['field'=><cfId>, 'value'=><val>]
            //foreach ($cfUpdatesById as $cfId => $val) {
            //    $cfPatches[] = ['field' => (int)$cfId, 'value' => $val];
            //}
            $patches = $wf->syncCustomFieldsAndValidate($docId, $ex, $nextState, $pl);
            $cfPatches = [];
            foreach ($patches /* dein Dict */ as $fid => $val) {
                // Typ-Sauberkeit:
                //if ((int)$fid === 15) { // falls 15 = Betrag (decimal)
                //    $val = number_format((float)$val, 2, '.', ''); // "7676.00"
                //}
                $cfPatches[] = ['field' => (int)$fid, 'value' => $val];
            }

            // 5) EIN PATCH – alles zusammen
            $code = 0;
            $body = '';
            Log::j('DEBUG', 'AtomicPatchBeforeCall', ['Title' => $newTitle, 'Tags' => $finalTagIds, 'Patches' => $cfPatches]);
            $ok = $pl->patchDocumentAtomic(
                $docId,
                $newTitle,        // null, wenn du den Titel nicht ändern willst
                $finalTagIds,      // null, wenn Tags unverändert bleiben sollen
                $cfPatches,        // null, wenn keine CF-Updates anstehen
                $code,
                $body
            );
            $repo->wfSetState($docId, $nextState, $newTitle, $typeName);

            if (!$ok) {
                Log::j('DEBUG', 'AtomicPatch', ['ok' => $ok, 'Title' => $newTitle, 'Tags' => $finalTagIds, 'Patches' => $cfPatches]);
            }
            break;

        // =============================================================================================        // =============================================================================================

        // UNVOLL ist ein Status, wo der Benutzer gefragt ist. Er muss nun in den Benutzerfeldern
        // von Paperless die fehlenden Daten ergänzen. Danach kann er den Status wieder auf PRUEFEN setzen
        // hier fehlt aktuell allerdings noch die Logik, dass bei Status = PRUEFEN geprüft wird,
        // ob nicht bereits ein dms_extract-Tabelleneintrag vorhanden ist. Wenn ja, muss dann
        // auf die Vollständigkeit der Paperless Benutzerfelder geprüft werden

        case "UNVOLL":
            break;

        // =============================================================================================
        // APP_REQ -> hier ist der Benutzer am Werk und muss die Freigabe der Rechnung prüfen

        case "APP_REQ":
            break;

        case "REACT":
            // dieser Status wird genutzt, wenn ein Dokument erneut in den Workflow aufgenommen werden
            // soll. Z.B. wenn eine Rechnung fälschlicherweise auf "CLOSE" gesetzt wurde, aber
            // diese doch noch einmal geprüft werden soll. Der Status wird manuell durch den Benutzer
            // gesetzt. 
            // Wir setzen den Status auf INIT zurück und der Poller wird das Dokument dann wieder
            // in die Prüfung aufnehmen

            // Hier fehlt noch die Logik, dass die Einträge für dms_extract und wf_job gelöscht werden
            // damit der Workflow wieder von vorne beginnen kann

            $repo->deleteExtract($docId);
            $repo->deleteWfJob($docId);

            $nextState = 'INIT';
            $targetKey = $nextState; // z.B. 'APP_REQ' oder 'UNVOLL' …
            $targetId  = (int)($T[$targetKey] ?? 0);

            // build wfhistory entry
            $repo->logWfHistory($docId, $currentState, $nextState, '-intern-');

            $finalTagIds = $wf->buildFinalTags($currentTagIds, $stateIds, $targetId);
            // wir schreiben keinen neuen Titel
            $newTitle = null;
            // es gibt auch keine Benutzerfeld-Patches
            $cfPatches = null;
            // nur die TagID setzen
            $code = 0;
            $body = '';
            //Log::j('DEBUG', 'AtomicPatchBeforeCall', ['Title' => $newTitle, 'Tags' => $finalTagIds, 'Patches' => $cfPatches]);
            $ok = $pl->patchDocumentAtomic(
                $docId,
                $newTitle,        // null, wenn du den Titel nicht ändern willst
                $finalTagIds,      // null, wenn Tags unverändert bleiben sollen
                $cfPatches,        // null, wenn keine CF-Updates anstehen
                $code,
                $body
            );
            if (!$ok) {
                Log::j('DEBUG', 'AtomicPatch', ['ok' => $ok, 'Title' => $newTitle, 'Tags' => $finalTagIds, 'Patches' => $cfPatches]);
            }
            $repo->wfSetState($docId, $nextState, '', $typeName);
            break;

        // =============================================================================================
        // APP_REJ -> Rechnungsfreigabe wurde verweigert -> Personal, welche die Rechnungen 
        // bearbeitet, muss informiert werden -> dann weiter mit PRUEFEN

        case "APP_REJ":
            $tplBodyRaw = $repo->get_variable_value('WF:freigabe_nicht_erteilt') ?? '';
            $tplBody    = $wf->normalize_template($tplBodyRaw); // <<< WICHTIG
            $notiz = $wf->normalize_note($doc['notes'] ?? $doc['note'] ?? '');
            $missing = [];

            $vars = [
                'DOK_ID'       => $docId,
                'MISSING_LIST' => $wf->build_missing_list($missing),
                'NOTIZ'        => $notiz,
                'URL'          => $href,                                  // für href und sichtbaren Text nutzbar
                'URL_TEXT'     => htmlspecialchars($href, ENT_NOQUOTES),  // falls du {{URL_TEXT}} im sichtbaren Teil nutzen willst
            ];
            $body = trim($tplBody) !== '' ? $wf->render_template($tplBody, $vars)
                : "<p>Freigabe nicht erteiltt<a href=\"{$href}\">öffnen</a></p>";
            $subject = "Rechnungsfreigabe nicht erteilt: Dok #{$docId}";
            $ntf->send(
                $repo->get_variable_value('WF_DEFAULT_ACTOR') ?: 'it_admin@albatros-hospiz.de',
                $subject,
                $body
            );
            // dieser Fall muss zurückdelegiert werden und erneut geprüft werden
            $nextState = 'PRUEFEN2';
            $targetKey = $nextState; // z.B. 'APP_REQ' oder 'UNVOLL' …
            $targetId  = (int)($T[$targetKey] ?? 0);

            // build wfhistory entry
            $repo->logWfHistory($docId, $currentState, $nextState, '-intern-');

            $finalTagIds = $wf->buildFinalTags($currentTagIds, $stateIds, $targetId);
            // wir schreiben keinen neuen Titel
            $newTitle = null;
            // es gibt auch keine Benutzerfeld-Patches
            $cfPatches = null;
            // nur die TagID setzen
            $code = 0;
            $body = '';
            //Log::j('DEBUG', 'AtomicPatchBeforeCall', ['Title' => $newTitle, 'Tags' => $finalTagIds, 'Patches' => $cfPatches]);
            $ok = $pl->patchDocumentAtomic(
                $docId,
                $newTitle,        // null, wenn du den Titel nicht ändern willst
                $finalTagIds,      // null, wenn Tags unverändert bleiben sollen
                $cfPatches,        // null, wenn keine CF-Updates anstehen
                $code,
                $body
            );
            if (!$ok) {
                Log::j('DEBUG', 'AtomicPatch', ['ok' => $ok, 'Title' => $newTitle, 'Tags' => $finalTagIds, 'Patches' => $cfPatches]);
            }
            $repo->wfSetState($docId, $nextState, '', $typeName);
            break;

        // =============================================================================================
        // APP_OK -> der Benutzer hat die Freigabe der Rechnung bestätigt -> es muss nun die SEPA-Datei
        // erstellt werden, sofern es sich nicht um einen Einzug handelt

        case "APP_OK":

            // 1) wir holen uns den Extract von tabelle dms_extract (es wird ja die KI hier nicht
            // mehr benötigt, denn alle notwendigen Werte für die SEPA Erstellung liegen bereits vor
            $row = $repo->getExtract($docId);  // SELECT * FROM dms_extract WHERE dms_document_id=?
            $ex = [
                'direct_debit'    => (bool)($row['direct_debit'] ?? false),
                'invoice_amount'   => $row['invoice_amount'] ?? null,
                'issuer_iban'  => $row['issuer_iban'] ?? null,
                'issuer_bic'   => $row['issuer_bic'] ?? null,
                'payment_purpose'   => $row['payment_purpose'] ?? null,
                'issuer_name' => $row['issuer_name'] ?? null,
                'invoice_number' => $row['invoice_number'] ?? null,
                'konto'          => $row['konto'] ?? 1204,
                'note'           => $row['note'] ?? null,
            ];

            // zur Sicherheit prüfen wir noch, ob es sich um einen Einzug handelt -> eigentlich
            // sollten wir hier nicht mehr landen, denn nach APP_REQ wird bereits geprüft, ob es
            // sich um einen Einzug handelt und dann wird dort sofort der Workflow abgeschlossen

            $isDirectDebit = !empty($ex['direct_debit']); // true = Einzug
            if ($isDirectDebit) {
                $nextState = "CLOSE";
            } else { // nun müssen wir nur noch die SEPA-Datei erstellen -> dann können wir den Workflow abschliessen
                $sepacreated = $wf->actionCreateSepa($docId, $ex, $opts, $repo);
                // wenn ein Fehler bei der Erstellung passiert ist, dann keine Update das aktuellen Status
                if (!$sepacreated) {
                    $repo->logWfHistory($docId, $currentState, 'ERROR', '-intern-');
                    return;
                } else {
                    $repo->logWfHistory($docId, $currentState, 'SEPA', '-intern-');
                    $tplBodyRaw = $repo->get_variable_value('WF:SEPA_erstellt') ?? '';
                    $tplBody    = $wf->normalize_template($tplBodyRaw); // <<< WICHTIG
                    $notiz = null;
                    $missing = [];

                    $vars = [
                        'DOK_ID'       => $docId,
                        'MISSING_LIST' => $wf->build_missing_list($missing),
                        'NOTIZ'        => $notiz,
                        'URL'          => $href,                                  // für href und sichtbaren Text nutzbar
                        'URL_TEXT'     => htmlspecialchars($href, ENT_NOQUOTES),  // falls du {{URL_TEXT}} im sichtbaren Teil nutzen willst
                    ];
                    $body = trim($tplBody) !== '' ? $wf->render_template($tplBody, $vars)
                        : "<p>SEPA-Datei erstellt<a href=\"{$href}\">öffnen</a></p>";
                    $subject = "SEPA-Datei erstellt: Dok #{$docId}";
                    $ntf->send(
                        $repo->get_variable_value('WF_DEFAULT_ACTOR') ?: 'it_admin@albatros-hospiz.de',
                        $subject,
                        $body
                    );
                    $nextState = 'CLOSE';
                }
            }

            // Status-Ziel & IDs
            $targetKey = $nextState;
            $targetId  = (int)($T[$targetKey] ?? 0);

            // 1) Aktuelle Tags holen (IDs) (doc und tagIDs haben wir ja schon -> siehe oben)
            //$doc = $pl->getDocument($docId, []);  // Detail reicht
            //$currentTagIds = array_map('intval', $doc['tags'] ?? []);

            // build wfhistory entry
            $repo->logWfHistory($docId, $currentState, $nextState, '-intern-');

            // 2) Finale Tagliste berechnen (exklusiver State)
            $finalTagIds = $wf->buildFinalTags($currentTagIds, $stateIds, $targetId);

            $code = 0;
            $body = '';
            $newTitle = null;
            $cfPatches = null;

            Log::j('DEBUG', 'AtomicPatchBeforeCall', ['Title' => $newTitle, 'Tags' => $finalTagIds, 'Patches' => $cfPatches]);
            $ok = $pl->patchDocumentAtomic(
                $docId,
                $newTitle,        // null, wenn du den Titel nicht ändern willst
                $finalTagIds,      // null, wenn Tags unverändert bleiben sollen
                $cfPatches,        // null, wenn keine CF-Updates anstehen
                $code,
                $body
            );
            $repo->wfSetState($docId, $nextState, '', $typeName);
            break;

        // =============================================================================================           
        // SEPA -> die SEPA-Datei wurde erstellt. Der Workflow kann nun für dieses Dokument geschlossen
        // werden. Dieser Status tritt normalerweise überhaupt nicht auf, denn nach APP_OK wird
        // geprüft, ob es sich um eine Überweisung handelt. Wenn nein, wird der Workflow abgeschlossen (CLOSE).
        // Wenn ja, wird die SEPA-Datei erstellt und dann der Status = CLOSE gestellt
        case "SEPA":
            $from = "CLOSE";
            $wf->applyStateTag($docId, $from, $T);
            break;


        // ============================================================================================= 
        // ein unbekannter Status -> hier sollte noch Code rein, um den Admin davon in Kenntnis zu 
        // setzen   
        default:
            break;
    }

    Log::j('INFO', 'process_one.done', ['doc' => $docId ?? '0', 'next' => $next ?? null, 'missing' => $missing ?? null]);
}

// ==================================================================================
// ---------------------------- Batch-Lauf ------------------------------------------
// ==================================================================================
function run_once_batch(
    array $opts,
    PaperlessClient $pl,
    Repository $repo,
    Extractor $ext,
    WF $wf,
    Notifier $ntf,
    array $T,
    array $S
): void {
    Log::j('INFO', 'batch.start', ['dry' => $opts['dry']]);
    $page = 1;
    $pageSize = 100;
    $count = 0;

    do {
        $ids = [$T['PRUEFEN'], $T['PRUEFEN2'], $T['APP_OK'], $T['APP_REJ'], $T['INIT'], $T['REACT']]; // Tag-IDs
        //Log::j('INFO', 'tag ids', ['ids' => $ids]);
        //$ids = [['WF:Pruefen' => $id_pruefen,'WF:Rechnungsfreigabe_erfolgt' => $id_app_ok, 'WF:Freigabe_verweigert' => $id_app_rej]];
        $res = $pl->getDocuments([
            'page'        => $page,
            'page_size'   => $pageSize,
            'tags__id__in' => implode(',', array_map('intval', $ids)), // alle müssen vorhanden sein
            'ordering'    => 'id',
        ]);
        if (!$res || empty($res['results'])) break;

        foreach ($res['results'] as $doc) {
            $id = (int)$doc['id'];
            process_one($id, $opts, new Config(), $pl, $repo, $ext, $wf, $ntf, $T, $S);
            $count++;
        }
        $page++;
    } while (!empty($res['next']));

    //Log::j('INFO', 'batch.done', ['processed' => $count]);
}

// ---------------------------- Helpers ----------------------------
function stateFromTags(array $doc, array $T): ?string
{
    Log::j('DEBUG', 'stateFromTags', ['doc' => $doc]);
    $tagIds = array_map('intval', $doc['tags'] ?? []);
    // Priorität: jeder Doc soll genau einen Status-Tag tragen; nimm den ersten gefundenen
    foreach (['WF:Close', 'WF:Error', 'WF:Freigabe_erfolgt', 'WF:SEPA_erzeugt', 'WF:Freigabe_erforderlich', 'WF:Freigabe_verweigert', 'WF:Daten_unvollständig', 'WF:Pruefen'] as $k) {
        if (in_array($T[$k] ?? -1, $tagIds, true)) return $k;
    }
    return null;
}

function backoffFetchContent(PaperlessClient $pl, int $docId, float $timeout = 90.0): array
{
    $deadline = microtime(true) + $timeout;
    $delay = 0.25;
    do {
        $doc = $pl->getDocument($docId, ['expand' => 'document_type']);
        $content = '';
        foreach (['content', 'raw_text_content', 'original_content', 'text', 'text_content'] as $f) {
            if (!empty($doc[$f]) && is_string($doc[$f])) {
                $content = $doc[$f];
                break;
            }
        }
        if ($content !== '') return [$doc, $content];
        usleep((int)($delay * 1_000_000));
        $delay = min($delay * 2, 2.0);
    } while (microtime(true) < $deadline);
    return [$doc ?? [], ''];
}


/** kleine cURL-Hilfe */
function httpGetJson(string $url, string $token, ?int &$code = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Authorization: Token ' . $token,
            'Expect:',
        ],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $ctype = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $errno = curl_errno($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($code !== 200) {
        \App\Log::j('WARN', 'http', [
            'url' => $url,
            'http' => $code,
            'ctype' => $ctype,
            'errno' => $errno,
            'error' => $err,
        ]);
    }
    $json = json_decode((string)$body, true);
    return is_array($json) ? $json : [];
}

/** Self-Check: 3 obligatorische Prüfungen */
function selfCheck(string $base, string $token): array
{
    $ok = true;
    $problems = [];



    // 2) Doctypes erreichbar
    $c2 = null;
    $u2 = $base . '/api/document_types/?page_size=1';
    $r2 = httpGetJson($u2, $token, $c2);
    if ($c2 !== 200) {
        $ok = false;
        $problems[] = "document_types http=$c2";
    }

    // 3) Tags API erreichbar (keine Namenssuche hier)
    $c3 = null;
    $u3 = $base . '/api/tags/?page_size=1';
    $r3 = httpGetJson($u3, $token, $c3);
    if ($c3 !== 200) {
        $ok = false;
        $problems[] = "tags http=$c3";
    }

    return [$ok, $problems];
}

/** Tag-ID nach Name (robust, mit Fallback) */
function resolveTagId(string $base, string $token, string $name): ?int
{
    $q = rawurlencode($name);

    foreach (["search=$q", "name__iexact=$q"] as $param) {
        $c = null;
        $r = httpGetJson("$base/api/tags/?$param&page_size=50", $token, $c);
        if ($c === 200 && !empty($r['results'])) {
            foreach ($r['results'] as $t) {
                if (strcasecmp($t['name'] ?? '', $name) === 0) return (int)$t['id'];
            }
        }
    }
    // Fallback: alles durchblättern
    for ($url = "$base/api/tags/?page_size=100"; $url;) {
        $c = null;
        $r = httpGetJson($url, $token, $c);
        if ($c !== 200) break;
        foreach ($r['results'] ?? [] as $t) {
            if (strcasecmp($t['name'] ?? '', $name) === 0) return (int)$t['id'];
        }
        $url = $r['next'] ?? null;
    }
    return null;
}

/** Tag-Map initialisieren + cachen, nie crashen */
function bootstrapTagMap(array $needed, string $base, string $token, string $cacheFile): array
{
    $map = [];
    //if (is_readable($cacheFile)) {
    //    $raw = json_decode((string)@file_get_contents($cacheFile), true);
    //    if (is_array($raw)) $map = $raw;
    //}
    foreach ($needed as $key => $tagName) {
        if (!empty($map[$key])) continue;
        $id = resolveTagId($base, $token, $tagName);
        if ($id) {
            $map[$key] = $id;
            \App\Log::j('DEBUG', 'tag_resolved', ['key' => $key, 'name' => $tagName, 'id' => $id]);
        } else {
            \App\Log::j('WARN', 'tag_missing', ['key' => $key, 'name' => $tagName]);
        }
    }
    @file_put_contents($cacheFile, json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    return $map;
}
