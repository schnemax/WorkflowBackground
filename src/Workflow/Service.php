<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Paperless\Client as PaperlessClient;
use App\DB\Repository;
use App\Notify\Notifier;
use App\Sepa\SepaService;
use App\Sepa\SimpleSepa;
use App\Log;

final class Service
{
    public function __construct(
        private PaperlessClient $pl,
        private Repository $repo,
        private ?Notifier $notifier = null,
        private ?SepaService $sepa = null
    ) {}



    public function buildTitle(string $type, ?string $issuer, array $ex, ?string $invDate): string
    {
        
        $parts = array_filter([
            //$type,
            $issuer ? trim($issuer) : null,
            $invDate ? date('Y-m-d', strtotime($invDate)) : null,
            //isset($ex['amt']) && $ex['amt']!==null ? number_format((float)$ex['amt'],2,',','.') . ' €' : null,
            !empty($ex['inv']) ? 'ReNr ' . $ex['inv'] : null,
        ]);
        return implode(' · ', $parts);
    }
    // Lädt fieldmap.json einmal (mit Caching)
    public function loadFieldMap(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;
        $file = __DIR__ . "/../../config/fieldmap.json";
        $cache = is_file($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
        return $cache;
    }

    // Synchronisiert Extraktwerte in Custom Fields & validiert Pflichtfelder
    public function syncCustomFieldsAndValidate(
        int $docId,
        array $ex,                 // Extrakt: inv, amt, issuer_name, ...
        string $state,             // z. B. 'APP_REQ'
        PaperlessClient $pl        // dein bestehender Client
    ): array {
        $map = $this->loadFieldMap();
        //Log::j('INFO', 'Custom-Field-Map', ['map' => $map]);
        // 1) Hole Custom-Field-Definitionen einmal (Name → ID, Typ)
        $defs = $pl->listCustomFields(); // deine bestehende Methode
        $byName = [];
        foreach ($defs as $f) {
            $nm = trim((string)$f['name']);
            if ($nm === '') continue;
            // choices_by_label aufbauen, falls nicht gesetzt
            if (empty($f['choices_by_label']) && !empty($f['choices'])) {
                $map = [];
                foreach ($f['choices'] as $ch) {
                    $label = (string)($ch['label'] ?? $ch['name'] ?? '');
                    $cid   = (string)($ch['id'] ?? '');
                    if ($label !== '' && $cid !== '') {
                        $map[mb_strtolower($label, 'UTF-8')] = $cid;
                    }
                }
                $f['choices_by_label'] = $map;
            }
            $byName[$nm] = $f;
        }

        // 2) Baue Document-PATCH Payload je nach Paperless-API
        // a) Normalisiere & mappe Werte
        $patchCustom = [];              // Form 1: [["field"=>id,"value"=>...], ...]
        $missing = [];

        foreach ($map as $exKey => $fm) {
            $value = $ex[$exKey] ?? null;

            // Pflichtfeldprüfung für diesen Zustand
            $req = in_array($state, ($fm['required_in'] ?? []), true);
            if ($req && ($value === null || $value === '')) {
                $missing[] = $exKey;
            }
            //Log::j('INFO', 'Custom-Field', ['List' => $exKey, 'Value=' => $value, 'Req' => $req]);
            //Log::j('INFO', 'Custom-Field-Remaining', ['MissingList' => $missing]);
            // Mapping nur, wenn Wert vorhanden
            if ($value === null || $value === '') continue;

            // Feldauflösung
            $fieldName = $fm['field'];
            $def = $byName[$fieldName] ?? null;
            if (!$def) continue; // unbekanntes Feld → ignoriere (oder lege an)

            // Typ-Normalisierung
            $type = strtolower((string)($fm['type'] ?? $def['data_type'] ?? 'text'));
            switch ($type) {
                case 'date':
                    // ISO-Format an Paperless geben
                    $value = substr((string)$value, 0, 10);
                    break;
                case 'decimal':
                    $value = number_format((float)$value, 2, ',', '.');
                    break;
                case 'iban':
                    $value = (string)$value;
                    break;
                case 'bic':
                    $value = strtoupper(preg_replace('/\s+/', '', (string)$value));
                    break;
                case 'select':
                    // $fm['field'] ist der Anzeigename (z. B. "Zahlart") aus deiner fieldmap.json
                    $fieldName = $fm['field'];
                    $def = $byName[$fieldName] ?? null;

                    if (!$def) {
                        \App\Log::j('WARN', 'CF field not found by name', ['name' => $fieldName]);
                        continue 2;
                    }

                    $choiceId = $this->selectChoiceId($value, $def, $pl);
                    if ($choiceId === null) {
                        \App\Log::j('WARN', 'CF select cannot map', [
                            'field' => $fieldName,
                            'provided' => $value,
                            'choices' => $def['choices'] ?? []
                        ]);
                        // Wenn required_in und kein Mapping → missing markieren
                        if (in_array($state, ($fm['required_in'] ?? []), true)) {
                            $missing[] = $exKey;
                        }
                        continue 2;
                    }

                    $value = $choiceId;
                    $ex[$exKey] = $value;
                    // Achtung: $def wurde evtl. von selectChoiceId() angereichert → $byName updaten, damit Folgefelder profitieren
                    //$byName[$fieldName] = $def;
                    break;

                default:
                    $value = (string)$value;
            }

            // 3) Payload-Eintrag erstellen (je nach Paperless-Version)
            // Variante A (häufig): array von Objekten
            $patchCustom[] = ['field' => (int)$def['id'], 'value' => $value];
            // Variante B (manche Builds): map id->value
            // $patchCustom[(int)$def['id']] = $value;
        }

        // $byName wurde vorher aus listCustomFields() gefüllt
        if ($patchCustom) {
            $valuesById = [];

            foreach ($map as $exKey => $fm) {

                $value = $ex[$exKey] ?? null;
                if ($value === null || $value === '') continue;
                //Log::j('DEBUG', "patchexKey", ['exKey' => $exKey, 'value' => $value]);

                $def = $byName[$fm['field']] ?? null;    // Felddefinition über NAME
                if (!$def || empty($def['id'])) continue;

                // Typ-normalisieren wie gehabt (date/decimal/iban/bic/select…)
                // ...
                //Log::j('DEBUG', "patchCustom", ['def' => $def, 'value' => $value, 'fm' => $fm]);
                $valuesById[(int)$def['id']] = $value;
            }
        }

        return $valuesById;
    }



    public function allowedTransitions(): array
    {
        // erlaubte Übergänge: nur Keys
        $ALLOWED = [
            'INIT' => ['INIT', 'PRUEFEN', 'CLOSE', 'ERROR'],
            'PRUEFEN' => ['UNVOLL', 'APP_REQ', 'ERROR'],
            'UNVOLL'  => ['APP_REQ', 'ERROR'],
            'APP_REQ' => ['APP_OK', 'APP_REJ', 'UNVOLL', 'ERROR'],
            'APP_OK'  => ['SEPA', 'CLOSE', 'ERROR'],
            'APP_REJ' => ['PRUEFEN2', 'PRUEFEN', 'CLOSE', 'ERROR'],
            'SEPA'    => ['CLOSE', 'ERROR'],
            'CLOSE'   => [],        // final
            'ERROR'   => ['PRUEFEN'] // back to start
        ];
        return $ALLOWED;
    }
    public function enforceTransition(
        int $docId,
        string $desiredState,     // aus Tags im Dokument ermittelt
        string $prevState,        // aus wf_jobs.state (dein „Wahrheitsspeicher“)
        array $stateTags,         // Mapping 'StateName' => TagId
        string $title,
        string $document_type,
        $notify,
        string $notiz
    ): string {
        $allowed = $this->allowedTransitions();
        if ($desiredState === $prevState) return $prevState;

        $ok = in_array($desiredState, $allowed[$prevState] ?? [], true);
        if ($ok) {
            // akzeptieren: DB aktualisieren
            $this->repo->wfSetState($docId, $desiredState, $title, $document_type);
            return $desiredState;
        }

        // ROLLBACK: unzulässig → Tags korrigieren, Hinweis senden
        //$this->replaceTags($docId, [$stateTags[$desiredState] ?? null], [$stateTags[$prevState] ?? null]);
        \App\Log::j('DEBUG', 'ReplaceTags', ['prevState' => $prevState, 'desiredState' => $desiredState]);
        $msg = "Ungültiger Statuswechsel von '{$prevState}' nach '{$desiredState}'. Zustand wurde zurückgesetzt.";
        // optional: Paperless-Dokument-Notiz setzen (falls du eine helper-Methode hast)
        // $this->pl->appendNote($docId, $msg);
        $notify->send(
            getenv('WF_ESCALATION_EMAIL') ?: 'it_admin@albatros-hospiz.de',
            "Rollback Statuswechsel Dok#{$docId}",
            "<p>{$msg}</p><p><a href=\"{$this->pl->documentUrl($docId)}\">Dokument öffnen</a></p>"
        );
        return $prevState;
    }
    // In deiner Service-Klasse:
    private array $tagCache = []; // name(lower) => id

    public function ensureTag(string $name): int
    {
        $key = mb_strtolower(trim($name), 'UTF-8');
        if ($key === '') {
            throw new \InvalidArgumentException('ensureTag(): empty tag name');
        }

        // 1) Cache
        if (isset($this->tagCache[$key])) {
            return $this->tagCache[$key];
        }

        // 2) Versuch: auf dem Server finden
        $id = $this->pl->findTagIdByName($name);
        if ($id) {
            return $this->tagCache[$key] = $id;
        }

        // 3) Anlegen
        $created = $this->pl->createTag($name);
        if (is_array($created) && !empty($created['id'])) {
            return $this->tagCache[$key] = (int)$created['id'];
        }

        // 4) Race-Condition-Fallback: direkt nach Create nochmal suchen
        $id = $this->pl->findTagIdByName($name);
        if ($id) {
            return $this->tagCache[$key] = $id;
        }

        throw new \RuntimeException("ensureTag(): failed to ensure tag '{$name}'");
    }
    // App/Workflow/Service.php

    public function enforceAndResolveState(
        int $docId,
        array $T,                 // Key => Tag-ID (PRUEFEN,...)
        array $ALLOWED,            // Key => [erlaubte Next-Keys]
        array $tagIds,
        string $title,
        string $document_type
    ): string {
        // 1) letzter bekannter State (sonst INIT)
        $prev = $this->repo->wfGetState($docId) ?: 'INIT';
        if (!isset($T[$prev])) $prev = 'INIT';

        // 3) State-IDs & ID→Key Map
        $stateIds = [];
        $id2key   = [];
        foreach ($T as $key => $id) {
            $id = (int)$id;
            if ($id > 0) {
                $stateIds[] = $id;
                $id2key[$id] = $key;
            }
        }

        // 4) State-Keys, die aktuell am Dokument hängen
        $docStateKeys = [];
        foreach ($tagIds as $id) {
            if (isset($id2key[$id])) $docStateKeys[] = $id2key[$id];
        }
        $docStateKeys = array_values(array_unique($docStateKeys));

        // 5) erlaubte Keys aus prev (prev selbst zulassen)
        $allowedKeys = array_unique(array_merge([$prev], $ALLOWED[$prev] ?? []));

        // 6) von den am Doc vorhandenen nur erlaubte behalten
        $keepAllowed = array_values(array_intersect($docStateKeys, $allowedKeys));

        // 7) Entscheidung: welchen State nehmen?
        //    - Wenn erlaubte vorhanden → nimm höchste Priorität
        //    - Sonst prev
        $priority = ['CLOSE', 'ERROR', 'SEPA', 'APP_OK', 'APP_REQ', 'UNVOLL', 'PRUEFEN2', 'PRUEFEN', 'INIT', 'APP_REJ'];
        $chosen = $prev;
        if ($keepAllowed) {
            usort(
                $keepAllowed,
                fn($a, $b) =>
                array_search($a, $priority, true) <=> array_search($b, $priority, true)
            );
            $chosen = $keepAllowed[0];
        }

        // 8) finale Tagliste bauen:
        //    - Alle State-IDs entfernen
        //    - chosen-ID hinzufügen
        $keptNonState = array_values(array_diff($tagIds, $stateIds));
        $final = array_values(array_unique(array_merge($keptNonState, [(int)$T[$chosen]])));

        // 10) State persistieren + Logging
        $this->repo->wfSetState($docId, $chosen, $title, $document_type);
        \App\Log::j('DEBUG', 'wf.state.enforced', [
            'doc' => $docId,
            'prev' => $prev,
            'doc_states' => $docStateKeys,
            'allowed' => $allowedKeys,
            'chosen' => $chosen,
            'final_tags' => $final
        ]);

        return $chosen; // z. B. 'APP_REQ'
    }

    private function selectChoiceId($value, array &$def, \App\Paperless\Client $pl): ?string
    {
        // Falls bisher keine Choices in $def, einmal nachladen
        if (empty($def['choices_by_label']) && !empty($def['id'])) {
            $fresh = $pl->getCustomFieldDef((int)$def['id']);
            if ($fresh) $def = $fresh; // $def anreichern für spätere Aufrufe
        }
        //\App\Log::j('DEBUG', 'GotCustomFieldDef', ['def' => $def]);
        $byLabel = $def['select_options'] ?? [];
        //        if (!$byLabel) return null;

        // Bool/0/1 → Label
        if (is_bool($value)) {
            $value = $value ? 'Einzug' : 'Ueberweisung';
        } elseif ($value === 1 || $value === '1') {
            $value = 'Einzug';
        } elseif ($value === 0 || $value === '0') {
            $value = 'Ueberweisung';
        }
        $choiceId = self::mapSelectLabelToId($value, $def);
        if ($choiceId !== null) {
            //\App\Log::j('DEBUG', 'returned Value1', ['choiceId' => $choiceId]);
            return $choiceId;
        }

        // Label → ID
        $k = mb_strtolower((string)$value, 'UTF-8');
        //\App\Log::j('DEBUG', 'returned Value2', ['value' => $value]);
        return $byLabel[$k] ?? null;
    }
    /**
     * Mappt einen Wert/Label (z. B. "Einzug") auf die Choice-ID des Select-Felds.
     * $def ist die CF-Definition; bei dir sind die Optionen in $def['extra_data']['select_options'].
     */
    public function mapSelectLabelToId($value, array $def): ?string
    {
        // Optionen einsammeln (verschiedene API-Varianten abdecken)
        $opts = $def['extra_data']['select_options']
            ?? $def['select_options']
            ?? $def['choices']
            ?? [];

        // Bool/0/1 auf Label abbilden
        if (is_bool($value) || $value === 0 || $value === 1 || $value === '0' || $value === '1') {
            $value = ($value === true || $value === 1 || $value === '1') ? 'Einzug' : 'Ueberweisung';
        }
        $needle = self::normalize_label((string)$value);

        foreach ($opts as $ch) {
            $label = (string)($ch['label'] ?? $ch['name'] ?? '');
            $id    = (string)($ch['id']    ?? '');
            if ($id === '') continue;

            if (self::normalize_label($label) === $needle) {
                //\App\Log::j('DEBUG', 'select mapped', ['label' => $label, 'id' => $id]);
                return $id; // <-- die Choice-ID zurückgeben!
            }
        }

        \App\Log::j('WARN', 'select no match', [
            'provided' => $value,
            'opts' => array_map(fn($o) => $o['label'] ?? '', $opts),
        ]);
        return null;
    }

    public function normalize_label(string $s): string
    {
        $s = trim($s);
        // Umlaute/Fallschreibung vereinheitlichen
        $s = strtr($s, ['Ä' => 'Ae', 'ä' => 'ae', 'Ö' => 'Oe', 'ö' => 'oe', 'Ü' => 'Ue', 'ü' => 'ue', 'ß' => 'ss']);
        return mb_strtolower($s, 'UTF-8');
    }
    // App/Workflow/Service.php
    public function applyStateTag(int $docId, string $stateKey, array $T): void
    {
        $targetId = (int)($T[$stateKey] ?? 0);
        if ($targetId <= 0) {
            //\App\Log::j('ERROR', 'applyStateTag: missing target', ['doc' => $docId, 'state' => $stateKey]);
            return;
        }

        // Alle State-Tag-IDs (die exklusiv sein sollen)
        $stateIds = array_values(array_filter([
            $T['PRUEFEN'] ?? null,
            $T['UNVOLL'] ?? null,
            $T['APP_REQ'] ?? null,
            $T['APP_OK'] ?? null,
            $T['SEPA']   ?? null,
            $T['CLOSE']   ?? null,
            $T['ERROR'] ?? null,
        ], fn($v) => !is_null($v)));

        // Aktuelle Tags (IDs) holen – sowohl tags als auch tags_data abdecken
        $doc = $this->pl->getDocument($docId, ['expand' => 'tags']);
        $current = [];
        if (!empty($doc['tags']) && is_array($doc['tags'])) {
            $current = array_map('intval', $doc['tags']);
        } elseif (!empty($doc['tags_data']) && is_array($doc['tags_data'])) {
            foreach ($doc['tags_data'] as $t) if (isset($t['id'])) $current[] = (int)$t['id'];
        }

        // Nicht-State-Tags behalten, alte State-Tags entfernen
        $kept  = array_values(array_diff($current, $stateIds));
        $final = array_values(array_unique(array_merge($kept, [$targetId])));

        // PATCH + Debug
        $payload = ['tags' => $final]; // Paperless erwartet eine Liste von Tag-IDs

        // Verifizieren (neu laden)
        $after  = $this->pl->getDocument($docId, []);
        $afterIds = array_map('intval', $after['tags'] ?? []);
        $has = in_array($targetId, $afterIds, true);
        \App\Log::j($has ? 'INFO' : 'ERROR', 'applyStateTag.verify', [
            'doc' => $docId,
            'target' => $targetId,
            'after' => $afterIds
        ]);
    }



    /** SEPA erstellen (Credit Transfer) – Minimalbeispiel */
    public function actionCreateSepa(int $docId, array $ex, array $opts, $repo): bool
    {
        if (!$this->sepa) {
            Log::j('ERROR', 'SEPA service missing', ['doc' => $docId]);
            return false;
        }

        $file = $this->sepa->generate_sepa_pain001($ex, $docId, $repo);
        if (!$file) return false;

        // optional: Mail-Hinweis
        if ($this->notifier && empty($opts['dry'])) {
            $to = getenv('WF_DEFAULT_SEPA_ACTOR') ?: 'it_admin@albatros-hospiz.de';
            $this->notifier->send(
                $to,
                "SEPA erstellt: Dok #$docId",
                '<p>SEPA-Datei erstellt.</p>
                <p>Holen Sie die SEPA-Datei an dem Ihnen bekanntgemachten Ordner ab und reichen diesen bei der Bank ein</p>
                <p>Hier k&ouml;nnen Sie das zugrundeliegende Dokument (nicht die SEPA-Datei) nochmals einsehen
                <a href="' . $this->pl->documentUrl($docId) . '">Dokument &ouml;ffnen</a></p>'
            );
        }
        return true;
    }





    private function normAmountCF($v): ?float
    {
        if ($v === null || $v === '') return null;
        $s = str_replace(['.', ' '], ['', ''], (string)$v);
        $s = str_replace(',', '.', (string)$v);
        return is_numeric($s) ? round((float)$s, 2) : null;
    }


    private function isValidBic(?string $s): bool
    {
        if (!$s) return false;
        return (bool)preg_match('~^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}([A-Z0-9]{3})?$~', $s);
    }
    private function labelIsDD(string $label): bool
    {
        $n = mb_strtolower(trim($label), 'UTF-8');
        return in_array($n, ['einzug', 'lastschrift', 'sepa-einzug'], true);
    }

    /**
     * Holt CFs (expand=custom_fields), mappt sie per Namen und normalisiert auf dein internes $ex-Format.
     * $cfMap: key in $ex => CF-Name in Paperless
     *   z.B.: ['issuer_name'=>'Rechnungssteller','invoice_number'=>'Rechnungsnummer', 'invoice_date'=>'Rechnungsdatum', 'invoice_amount'=>'Betrag','issuer_iban'=>'IBAN','issuer_bic'=>'BIC','payment_purpose'=>'Verwendungszweck','direct_debit'=>'Zahlart']
     */
    public function collectExFromCF(int $docId, array $cfMap, \App\Paperless\Client $pl, $repo): array
    {
        $doc = $pl->getDocument($docId, ['expand' => 'custom_fields']);
        $defs = [];
        $byName = [];

        foreach (($doc['custom_fields'] ?? []) as $inst) {
            $fid = (int)($inst['field'] ?? 0);
            if (!$fid) continue;
            $def = $defs[$fid] ??= ($pl->getCustomFieldDef($fid) ?? []);
            $name = trim((string)($def['name'] ?? ''));
            $val  = $inst['value'] ?? null;

            // Select → label auflösen (statt Choice-ID)
            if (($def['data_type'] ?? $def['type'] ?? '') === 'select') {
                $label = null;
                foreach (($def['extra_data']['select_options'] ?? $def['choices'] ?? []) as $ch) {
                    if ((string)($ch['id'] ?? '') === (string)$val) {
                        $label = (string)($ch['label'] ?? '');
                        break;
                    }
                }
                $val = $label ?? $val;
            }
            $byName[$name] = $val;
            //Log::j('DEBUG', 'cfMap-byName', ['name' => $byName[$name], 'value' => $val]);
        }

        // Auf dein $ex abbilden (kurze Keys wie bei dir im Code)
        $ex = [];
        $get = fn(string $cfName) => $byName[$cfName] ?? null;
        Log::j('DEBUG', 'cfMap', ['map' => $cfMap]);

        if (isset($cfMap['issuer_name']))     $ex['issuer_name'] = trim((string)($get($cfMap['issuer_name']) ?? ''));
        if (isset($cfMap['invoice_number']))  $ex['invoice_number']  = trim((string)($get($cfMap['invoice_number']) ?? ''));
        if (isset($cfMap['invoice_date']))    $ex['invoice_date'] = ($d = (string)($get($cfMap['invoice_date']) ?? '')) ? date('Y-m-d', strtotime($d)) : null;
        if (isset($cfMap['invoice_amount']))  $ex['invoice_amount']  = ($get($cfMap['invoice_amount']));
        if (isset($cfMap['issuer_iban']))     $ex['issuer_iban'] = $this->normIbanStrict((string)($get($cfMap['issuer_iban']) ?? ''));
        if (isset($cfMap['issuer_bic'])) {
            $bic = strtoupper(preg_replace('~\s+~', '', (string)($get($cfMap['issuer_bic']) ?? '')));
            $ex['issuer_bic'] = $this->isValidBic($bic) ? $bic : null;
        }
        if (isset($cfMap['payment_purpose'])) $ex['payment_purpose']  = trim((string)($get($cfMap['payment_purpose']) ?? ''));
        if (isset($cfMap['direct_debit'])) {
            $lab = (string)($get($cfMap['direct_debit']) ?? '');
            $ex['direct_debit'] = $lab !== '' ? $this->labelIsDD($lab) : null;
        }
        // Spezialbehandlung für die Variable 'Konto' (Überweisungskonto) -> dieser Wert kommt natürlich
        // nie vom Dokumentenextrakt, wird aber hier per Default gesetzt, damit dieses Feld im Paperless-Dokument
        // als Benutzerfeld sofort sichtbar wird. Es wird hier nicht unterschieden, ob es sich beim Dokument um 
        // eine Rechnung oder anderes handelt. Damit erscheint natürlich der Wert auch bei Dokumententypen, welche
        // nicht für die Überweisung relevant sind

        if ($ex['konto'] ?? null === null or $ex['konto'] ?? '' === '') {
            $ex['konto'] = $repo->get_variable_value('WF_DEFAULT_KONTO') ?: '1204';
        }
        return $ex;
    }
    /** prüft Pflichtfelder ausschließlich anhand CF-Werte */
    public function validateCF(array $ex, $repo): array
    {
        $missing = [];

        if (empty($ex['issuer_name'])) $missing['issuer_name'] = 'Rechnungssteller fehlt';
        if (empty($ex['invoice_number']))         $missing['invoice_number'] = 'Rechnungsnummer fehlt';
        if (empty($ex['invoice_date']))        $missing['invoice_date'] = 'Rechnungsdatum fehlt';
        if (empty($ex['invoice_amount']) || $ex['invoice_amount'] <= 0) $missing['invoice_amount'] = 'Betrag fehlt/0';

        $iban = $this->normIbanStrict($ex['issuer_iban'] ?? null);
        if ($iban === null) {
            $missing['issuer_iban'] = 'IBAN fehlt/ungültig (Prüfziffer)';
        } else {
            $ex['iban'] = $iban; // sauber normalisiert (upper, ohne Spaces)
        }

        // BIC optional: nur prüfen, wenn vorhanden
        if (isset($ex['issuer_bic']) && $ex['issuer_bic'] !== null && !$this->isValidBic($ex['issuer_bic'])) {
            $missing['issuer_bic'] = 'BIC ungültig';
        }
        // direct_debit optional – wenn du zwingend eine Entscheidung brauchst, entkommentieren:
        // if (!isset($ex['dd'])) $missing['direct_debit'] = 'Zahlart fehlt';
        if (empty($ex['konto'])) {
            $missing['konto'] = 'Ueberweisungskonto fehlt';
        } else {
            $valid = $repo->check_konto($ex['konto']);
            if (!$valid) {
                $missing['konto'] = 'Ueberweisungskonto ist ungueltig';
            }
        }

        return $missing;
    }



    /**
     * Baut die *finale* Tagliste:
     *   - entfernt alle State-Tags
     *   - fügt den gewünschten State-Tag hinzu
     *   - erhält sonstige (fachliche) Tags
     */
    public function buildFinalTags(array $currentIds, array $stateIds, int $targetStateId): array
    {
        $kept = array_values(array_diff(array_map('intval', $currentIds), array_map('intval', $stateIds)));
        $final = array_values(array_unique(array_merge($kept, [(int)$targetStateId])));
        return $final;
    }



    public function render_template(string $tpl, array $vars): string
    {
        return preg_replace_callback('/\{\{\s*([A-Z0-9_]+)\s*\}\}/', function ($m) use ($vars) {
            $k = $m[1];
            return array_key_exists($k, $vars) ? (string)$vars[$k] : $m[0];
        }, $tpl);
    }

    /**
     * Entfernt versehentliche JSON/addslashes-Escapes aus dem Template.
     * Beispiel: \"  \/  \n  etc.
     */
    public function normalize_template(string $s): string
    {
        // Versuch 1: JSON-Style unescapen (robust gegen \n, \t, \" , \/)
        $probe = @json_decode('"' . addcslashes($s, "\\\"") . '"');
        if (is_string($probe)) {
            return $probe;
        }
        // Fallback: PHP-Style
        return stripcslashes($s);
    }

    public function build_missing_list($missing): string
    {
        if (is_array($missing)) {
            $isAssoc = array_keys($missing) !== range(0, count($missing) - 1);
            $items = $isAssoc ? array_keys($missing) : $missing;
            return $items ? "• " . implode("\n• ", array_map('strval', $items)) : "—";
        }
        if (is_string($missing)) return trim($missing) === '' ? '—' : $missing;
        return '—';
    }

    // z.B. in App\Workflow\Service

    private function isFilled($v): bool
    {
        if ($v === null) return false;
        if (is_string($v)) return trim($v) !== '';
        return $v !== '';
    }

    /** optional: normalize helpers (nutzt deine vorhandenen Normierer, falls vorhanden) */
    private function normDate(?string $s): ?string
    {
        if (!$s) return null;
        $t = strtotime($s);
        return $t ? date('Y-m-d', $t) : null;
    }


    private function normBic(?string $s): ?string
    {
        if (!$s) return null;
        $s = strtoupper(preg_replace('~\s+~', '', $s));
        return preg_match('~^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}([A-Z0-9]{3})?$~', $s) ? $s : null;
    }

    /**
     * Merge CF (User) & KI-Extrakt:
     *  - CF hat Vorrang, wenn ausgefüllt
     *  - KI füllt Lücken
     *  - Konflikte werden geloggt
     * Keys: issuer_name, inv, date, amt, iban, bic, pur, dd
     */
    public function mergeEx(array $exKI, array $exCF): array
    {
        $fields = ['issuer_name', 'invoice_number', 'invoice_date', 'invoice_amount', 'issuer_iban', 'issuer_bic', 'payment_purpose', 'direct_debit', 'konto'];

        // Normalisieren beider Seiten in einheitliches Format
        $norm = function (string $k, $v) {
            switch ($k) {
                case 'invoice_datum':
                    return $this->normDate(($v !== null) ? (string)$v : null);
                case 'invoice_amount':
                    return $this->normAmountCF($v);
                case 'issuer_iban':
                    return $this->normIbanStrict(($v !== null) ? (string)$v : null);
                case 'issuer_bic':
                    return $this->normBic(($v !== null) ? (string)$v : null);
                case 'direct_debit':
                    return $v === null ? null : (bool)$v;
                default:
                    return ($v === null or $v === '') ? null : (is_string($v) ? trim($v) : $v);
            }
        };

        $merged = [];
        $conflicts = [];

        foreach ($fields as $k) {
            $cf = $norm($k, $exCF[$k] ?? null);
            $ki = $norm($k, $exKI[$k] ?? null);

            if ($this->isFilled($cf)) {
                $merged[$k] = $cf;
                if ($this->isFilled($ki) && $cf !== $ki) {
                    $conflicts[$k] = ['cf' => $cf, 'ki' => $ki];
                }
            } elseif ($this->isFilled($ki)) {
                $merged[$k] = $ki;
            } else {
                $merged[$k] = null;
            }
        }

        if ($conflicts) {
            \App\Log::j('INFO', 'mergeEx conflicts', $conflicts);
        }
        \App\Log::j('DEBUG', 'mergeEx result', ['merged' => $merged]);

        return $merged;
    }

    /**
     * Normalisiert das Paperless-"notes"/"note"-Feld zuverlässig zu String.
     * - String -> 1:1
     * - Array (Liste) -> Zeilen zusammengeführt
     * - Array (assoziativ) -> versucht 'content'/'note'/'text', sonst JSON
     * - Objekt -> __toString() oder JSON
     */
    function normalize_note(mixed $v): string
    {
        if ($v === null) return '';
        if (is_string($v)) return $v;
        if (is_array($v)) {
            // Assoziatives Array?
            $isAssoc = array_keys($v) !== range(0, count($v) - 1);
            if ($isAssoc) {
                if (isset($v['content'])) return (string)$v['content'];
                if (isset($v['note']))    return (string)$v['note'];
                if (isset($v['text']))    return (string)$v['text'];
                return json_encode($v, JSON_UNESCAPED_UNICODE);
            }
            // Liste: jedes Item zu Text machen
            $parts = [];
            foreach ($v as $item) {
                if (is_array($item)) {
                    $parts[] = (string)($item['content'] ?? $item['note'] ?? $item['text'] ?? json_encode($item, JSON_UNESCAPED_UNICODE));
                } elseif (is_object($item)) {
                    $parts[] = method_exists($item, '__toString') ? (string)$item : json_encode($item, JSON_UNESCAPED_UNICODE);
                } elseif (is_bool($item)) {
                    $parts[] = $item ? 'true' : 'false';
                } else {
                    $parts[] = (string)$item;
                }
            }
            return trim(implode("\n", array_filter($parts, static fn($s) => $s !== '' && $s !== null)));
        }
        if (is_object($v)) {
            return method_exists($v, '__toString') ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE);
        }
        if (is_bool($v)) return $v ? 'true' : 'false';
        return (string)$v;
    }
    // ===== 2) Missing-List Builder (HTML und Text) =====
    /**
     * $missing kann eine Liste von Schlüsseln sein (['issuer_name', ...])
     * oder eine assoziative Map fehlender Keys (['issuer_name' => true, ...]).
     */
    function normalize_missing_items($missing): array
    {
        if (!is_array($missing)) return [];
        $isAssoc = array_keys($missing) !== range(0, count($missing) - 1);
        return $isAssoc ? array_keys($missing) : array_values($missing);
    }

    function build_missing_list_html($missing, array $labels): string
    {
        $items = self::normalize_missing_items($missing);
        if (!$items) return '<em>—</em>';
        $html = '<ul>';
        foreach ($items as $k) {
            $label = $labels[$k] ?? $k;
            // Nur sichtbaren Text escapen
            $html .= '<li>' . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>';
        }
        $html .= '</ul>';
        return $html;
    }

    function build_missing_list_text($missing, array $labels): string
    {
        $items = self::normalize_missing_items($missing);
        if (!$items) return '—';
        // Text-Variante ohne Sonderzeichen (keine „â€¢“-Probleme)
        $lines = [];
        foreach ($items as $k) {
            $label = $labels[$k] ?? $k;
            $lines[] = '- ' . $label;
        }
        return implode("\n", $lines);
    }

    // In deinem Workflow\Service oder einem Utils-Helper

    /** Vollständige IBAN-Validierung inkl. Prüfziffer (MOD-97). */
    public function ibanIsValid(?string $iban): bool
    {
        if (!$iban) return false;
        $s = strtoupper(preg_replace('~\s+~', '', $iban));
        // Grundregeln
        if (!preg_match('~^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$~', $s)) return false; // 15..34 Gesamt, mind. 11 hinter CC+CD
        // Schritt 1: die ersten vier Zeichen ans Ende verschieben
        $rearr = substr($s, 4) . substr($s, 0, 4);
        // Schritt 2: Buchstaben in Zahlen (A=10 .. Z=35)
        $num = '';
        $len = strlen($rearr);
        for ($i = 0; $i < $len; $i++) {
            $ch = $rearr[$i];
            if ($ch >= '0' && $ch <= '9') {
                $num .= $ch;
            } else {
                $num .= (string)(ord($ch) - 55); // 'A'(65)->10
            }
        }
        // Schritt 3: MOD-97 iterativ (ohne BigInt)
        $rem = 0;
        $nlen = strlen($num);
        $chunk = '';
        for ($i = 0; $i < $nlen; $i++) {
            $chunk .= $num[$i];
            // um Zahlen zu groß zu vermeiden, moddele zwischendurch
            if (strlen($chunk) > 8) {
                $rem = (int)($chunk % 97);
                $chunk = (string)$rem;
            }
        }
        if ($chunk !== '') {
            $rem = (int)($chunk % 97);
        }
        //\App\Log::j('DEBUG', 'isIBANvalid', ['rem' => $rem]);
        return $rem === 1;
    }

    /** IBAN normalisieren + validieren: gibt normalisierte IBAN oder null zurück. */
    public function normIbanStrict(?string $s): ?string
    {
        if (!$s) return null;
        $s = strtoupper(preg_replace('~\s+~', '', $s));
        return $this->ibanIsValid($s) ? $s : null;
    }


    /** Gibt pro Feld zurück, ob und womit CF den KI-Wert ersetzt hat. */
    public function computeOverrides(array $exKI, array $exCF): array
    {
        // dieselben Keys wie im Merge
        $fields = ['issuer_name', 'inv', 'date', 'amt', 'iban', 'bic', 'pur', 'dd'];

        $isFilled = function ($v): bool {
            if ($v === null) return false;
            if (is_string($v)) return trim($v) !== '';
            return $v !== '';
        };

        $fmt = function (string $k, $v): string {
            if ($v === null) return '∅';
            if ($k === 'amt') return number_format((float)$v, 2, ',', '.'); // hübsch
            if ($k === 'dd')  return $v ? 'Einzug' : 'Überweisung';
            return (string)$v;
        };

        $over = [];
        foreach ($fields as $k) {
            $cf = $exCF[$k] ?? null;
            if (!$isFilled($cf)) continue;              // CF leer → kein Override
            $ki = $exKI[$k] ?? null;
            if ($ki === $cf) continue;                  // identisch → kein Override
            $over[$k] = ['from' => $ki, 'to' => $cf, 'from_str' => $fmt($k, $ki), 'to_str' => $fmt($k, $cf)];
        }
        return $over; // z. B. ['iban'=>['from'=>'DE12...','to'=>'DE34...'], ...]
    }
    /** Baut eine kleine HTML-Tabelle mit Overrides. */
    public function overridesHtml(array $over, array $labels = []): string
    {
        if (!$over) return '<p>Keine Unterschiede zwischen KI und Benutzerfeldern.</p>';

        // Standardlabels
        $def = [
            'issuer_name' => 'Rechnungssteller',
            'invoice_number' => 'Rechnungsnummer',
            'invoice_date' => 'Rechnungsdatum',
            'invoice_amount' => 'Betrag',
            'issuer_iban' => 'IBAN',
            'issuer_bic' => 'BIC',
            'payment_purpose' => 'Verwendungszweck',
            'direct_debit' => 'Zahlart',
        ];
        $L = array_merge($def, $labels);

        $rows = '';
        foreach ($over as $k => $chg) {
            $rows .= sprintf(
                '<tr><td style="padding:4px 8px;"><b>%s</b></td><td style="padding:4px 8px;color:#666;">%s</td><td style="padding:4px 8px;">→</td><td style="padding:4px 8px;"><b>%s</b></td></tr>',
                htmlspecialchars($L[$k] ?? $k),
                htmlspecialchars($chg['from_str'] ?? ''),
                htmlspecialchars($chg['to_str'] ?? '')
            );
        }
        return '<table border="0" cellpadding="0" cellspacing="0">' . $rows . '</table>';
    }

    public function common_send(int $docId, $doc, $what, $to, $subject, $missing, $overrides, $href, $wf, $repo, $ntf)
    {
        $tplBodyRaw = $repo->get_variable_value($what) ?? '';
        if ($overrides) {
            $tplBodyRaw . '<p><b>Vom Benutzer angepasste Felder:</b></p>' . $wf->overridesHtml($overrides);
        }
        $tplBody    = $wf->normalize_template($tplBodyRaw); // <<< WICHTIG
        $notiz = $wf->normalize_note($doc['notes'] ?? $doc['note'] ?? '');

        $vars = [
            'DOK_ID'       => $docId,
            'MISSING_LIST' => $wf->build_missing_list($missing),
            'NOTIZ'        => $notiz,
            'URL'          => $href,                                  // für href und sichtbaren Text nutzbar
            'URL_TEXT'     => htmlspecialchars($href, ENT_NOQUOTES),  // falls du {{URL_TEXT}} im sichtbaren Teil nutzen willst
        ];
        $body = trim($tplBody) !== '' ? $wf->render_template($tplBody, $vars)
            : "<p>" . $subject . ": <a href=\"{$href}\">öffnen</a></p>";
        $subject = $subject . ": Dok #{$docId}";
        $ntf->send(
            $repo->get_variable_value($to) ?: 'it_admin@albatros-hospiz.de',
            $subject,
            $body
        );
    }
 
}
