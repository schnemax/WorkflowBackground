<?php

namespace App\Sepa;

use App\Log;
use App\DB\Repository;
use App\Config;


final class SimpleSepa implements SepaService
{
    private string $pain;

    public function __construct(
        private Repository $repo,              // <-- Pflicht
        ?string $pain = null
    ) {
        $this->pain = $pain ?: (getenv('SEPA_PAIN') ?: 'pain.001.001.03');
    }

    public function generate_sepa_pain001(array $p, int $docId, $repo): string
    {
        Log::j('DEBUG', 'SEPA entered', ['docid' => $docId, 'p' => $p]);

        $ns = "urn:iso:std:iso:20022:tech:xsd/{$this->pain}";


        $fmtAmt = function ($x): string {
            return number_format((float)$x, 2, '.', '');
        };
        $today = (new \DateTime('now', new \DateTimeZone(getenv('TZ') ?: 'Europe/Berlin')))
            ->format('Y-m-d');
        $now   = (new \DateTime('now', new \DateTimeZone('UTC')))
            ->format('Y-m-d\TH:i:s\Z');

        $msgId = 'MSG-' . $docId . '-' . bin2hex(random_bytes(4));
        $pmtId = 'PMT-' . $docId;

        // Debtor (dein Unternehmen) aus Albatros Datenbank
        $debtorName_raw = $this->repo->DLookup("clientname", "parameter", "id='L'");
        $debtorName = self::sanitize($debtorName_raw, 70);

        //$debtorIban = kommt von den globalen Einstellungen der Albatros-Datenbank
        $query = "jahr = '" . date('Y') . "' and konto = '" . $p['konto'] . "'";
        $debtorIban = $this->repo->DLookup("iban", "konto", $query);
        $debtorBic = $this->repo->DLookup("bic", "konto", $query);

        // Pflichtfelder prüfen

        $amount     = (float)($p['invoice_amount'] ?? 0);  // darf natürlich nicht 0 sein -> ist schon geprüft
        $creditorName_raw = (string)($p['issuer_name'] ?? '');
        $creditorName = self::sanitize($creditorName_raw, 70);

        $creditorIban = preg_replace('/\s+/', '', (string)($p['issuer_iban'] ?? ''));

        if ($debtorName === '' || $debtorIban === '' || $creditorName === '' || $creditorIban === '' || $amount <= 0) {
            \App\Log::j('ERROR', 'SEPA missing data', compact('debtorName', 'debtorIban', 'creditorName', 'creditorIban', 'amount', 'docId'));
            return '';
        }

        // BIC noch überlegen, ob überhaupt mitgesendet werden soll -> ist innerhalb der EU nicht mehr nötig
        $creditorBic = (string)($p['issuer_bic'] ?? '');

        $purpose = $p['payment_purpose'] ?? '';
        if ($purpose === null or $purpose === '' or $purpose === ' ') {
            $purpose = substr((string)('Rechnung ' . $p['invoice_number']), 0, 140) ?? 'Unser Dokument ' . $docId;
        } else {
            $purpose     = substr((string)($p['payment_purpose']), 0, 140) ?? 'Unser Dokument ' . $docId;
        }
        $purpose_raw     = $purpose . " DMSID:" . $docId;
        $purpose = self::sanitize($purpose_raw, 140);

        $endToEnd    = $p['end_to_end'] ?? 'NOTPROVIDED';

        $xml = new \DOMDocument('1.0', 'UTF-8');
        $xml->preserveWhiteSpace = false;
        $xml->formatOutput = true;

        // <Document>
        $doc = $xml->createElementNS($ns, 'Document');
        $xml->appendChild($doc);

        // <CstmrCdtTrfInitn>
        $root = $xml->createElementNS($ns, 'CstmrCdtTrfInitn');
        $doc->appendChild($root);

        // GrpHdr
        $grp = $xml->createElementNS($ns, 'GrpHdr');
        $root->appendChild($grp);
        $grp->appendChild($xml->createElementNS($ns, 'MsgId', $msgId));
        $grp->appendChild($xml->createElementNS($ns, 'CreDtTm', $now));
        $grp->appendChild($xml->createElementNS($ns, 'NbOfTxs', '1'));
        $grp->appendChild($xml->createElementNS($ns, 'CtrlSum', $fmtAmt($amount)));
        $ip  = $xml->createElementNS($ns, 'InitgPty');
        $grp->appendChild($ip);
        $ip->appendChild($xml->createElementNS($ns, 'Nm', mb_substr($debtorName, 0, 70)));

        // PmtInf
        $pi = $xml->createElementNS($ns, 'PmtInf');
        $root->appendChild($pi);
        $pi->appendChild($xml->createElementNS($ns, 'PmtInfId', $pmtId));
        $pi->appendChild($xml->createElementNS($ns, 'PmtMtd', 'TRF'));
        $pi->appendChild($xml->createElementNS($ns, 'BtchBookg', 'false'));
        $pi->appendChild($xml->createElementNS($ns, 'NbOfTxs', '1'));
        $pi->appendChild($xml->createElementNS($ns, 'CtrlSum', $fmtAmt($amount)));

        $tti = $xml->createElementNS($ns, 'PmtTpInf');
        $pi->appendChild($tti);
        $svc = $xml->createElementNS($ns, 'SvcLvl');
        $tti->appendChild($svc);
        $svc->appendChild($xml->createElementNS($ns, 'Cd', 'SEPA'));

        $pi->appendChild($xml->createElementNS($ns, 'ReqdExctnDt', $today));

        // Debtor
        $dbtr = $xml->createElementNS($ns, 'Dbtr');
        $pi->appendChild($dbtr);
        $dbtr->appendChild($xml->createElementNS($ns, 'Nm', mb_substr($debtorName, 0, 70)));

        $dbtrAcct = $xml->createElementNS($ns, 'DbtrAcct');
        $pi->appendChild($dbtrAcct);
        $id = $xml->createElementNS($ns, 'Id');
        $dbtrAcct->appendChild($id);
        $id->appendChild($xml->createElementNS($ns, 'IBAN', $debtorIban));

        // Debtor Agent (BIC optional je nach pain-Version; wenn bekannt, mitsenden)
        // aktuell ignoriere ich den BIC, denn das würde andernfalls auch bedeuten, die BICs auf deren
        // Gültigkeit prüfen zu müssen usw.
        if (1 === 2) {
            if ($debtorBic !== '') {
                $dbtrAgt = $xml->createElementNS($ns, 'DbtrAgt');
                $pi->appendChild($dbtrAgt);
                $fi = $xml->createElementNS($ns, 'FinInstnId');
                $dbtrAgt->appendChild($fi);
                $fi->appendChild($xml->createElementNS($ns, 'BICFI', $debtorBic));
            }
        }

        $pi->appendChild($xml->createElementNS($ns, 'ChrgBr', 'SLEV'));

        // Einzelüberweisung
        $tx = $xml->createElementNS($ns, 'CdtTrfTxInf');
        $pi->appendChild($tx);

        $pid = $xml->createElementNS($ns, 'PmtId');
        $tx->appendChild($pid);
        $pid->appendChild($xml->createElementNS($ns, 'EndToEndId', $endToEnd));

        $amt = $xml->createElementNS($ns, 'Amt');
        $tx->appendChild($amt);
        $inst = $xml->createElementNS($ns, 'InstdAmt', $fmtAmt($amount));
        $inst->setAttribute('Ccy', 'EUR');
        $amt->appendChild($inst);

        if ($creditorBic !== '') {
            $cdtrAgt = $xml->createElementNS($ns, 'CdtrAgt');
            $tx->appendChild($cdtrAgt);
            $fi2 = $xml->createElementNS($ns, 'FinInstnId');
            $cdtrAgt->appendChild($fi2);
            $fi2->appendChild($xml->createElementNS($ns, 'BICFI', $creditorBic));
        }

        $cdtr = $xml->createElementNS($ns, 'Cdtr');
        $tx->appendChild($cdtr);
        $cdtr->appendChild($xml->createElementNS($ns, 'Nm', mb_substr($creditorName, 0, 70)));

        $cdtrAcct = $xml->createElementNS($ns, 'CdtrAcct');
        $tx->appendChild($cdtrAcct);
        $id2 = $xml->createElementNS($ns, 'Id');
        $cdtrAcct->appendChild($id2);
        $id2->appendChild($xml->createElementNS($ns, 'IBAN', $creditorIban));

        if ($purpose !== '') {
            $rmt = $xml->createElementNS($ns, 'RmtInf');
            $tx->appendChild($rmt);
            $rmt->appendChild($xml->createElementNS($ns, 'Ustrd', $purpose));
        }

        // speichern
        $dir = $repo->get_variable_value('WF_SEPA_PATH') ?? __DIR__ . '/../../var/out/sepa';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            \App\Log::j('ERROR', 'SEPA mkdir failed', ['dir' => $dir]);
            return '';
        }
        $file = sprintf('%s/sepa-%s-%d' . '.xml', $dir, $p['konto'], $docId);
        if ($xml->save($file) === false) return '';

        \App\Log::j('INFO', 'SEPA created', ['doc' => $docId, 'file' => $file, 'pain' => $this->pain]);
        return $file;
    }

    /**
     * SEPA-konforme Aufbereitung:
     * - Umlaute/ß umschlüsseln (ae/oe/ue/ss)
     * - nur A–Z a–z 0–9 ' + , - . : ? ( ) / und SPACE bleiben
     * - alles andere -> SPACE
     * - Mehrfach-Spaces zu einem SPACE
     * - trim
     * - optional kürzen auf $maxLen (null = kein Limit)
     */
    public static function sanitize(string $s, ?int $maxLen = 140): string
    {
        $s = trim($s);

        // 1) gezielte Umschlüsselung deutscher Umlaute/ß
        $s = strtr($s, [
            'Ä' => 'AE',
            'Ö' => 'OE',
            'Ü' => 'UE',
            'ä' => 'ae',
            'ö' => 'oe',
            'ü' => 'ue',
            'ß' => 'ss',
            '&' => 'und',
        ]);

        // 2) (optional) generische Transliteration, falls verfügbar
        //    (macht z.B. é -> e). Wir *lassen* erlaubte Satzzeichen unangetastet.
        if (function_exists('transliterator_transliterate')) {
            $s = transliterator_transliterate('Any-Latin; Latin-ASCII', $s);
        }

        // 3) nur erlaubte Klasse stehen lassen, Rest -> SPACE
        //    erlaubte Zeichen: A-Za-z0-9 ' + , - . : ? ( ) / und SPACE
        $allowed = "A-Za-z0-9 '\\+,\\-\\.\\:\\?\\(\\)\\/";
        $s = preg_replace('/[^' . $allowed . ']+/u', ' ', $s) ?? '';

        // 4) Mehrfach-Leerzeichen reduzieren und trimmen
        $s = preg_replace('/\\s+/', ' ', $s) ?? '';
        $s = trim($s);

        // 5) Feldlänge begrenzen (ISO20022: Name 70, Ustrd üblicherweise 140)
        if ($maxLen !== null) {
            $s = mb_substr($s, 0, $maxLen, 'UTF-8');
        }

        return $s;
    }

    /** Validierung gegen die obige Regel (true = bereits SEPA-konform) */
    public static function isValid(string $s, ?int $maxLen = 140): bool
    {
        $allowed = "A-Za-z0-9 '\\+,\\-\\.\\:\\?\\(\\)\\/";
        if ($s !== '' && !preg_match('/^[' . $allowed . ']+$/u', $s)) return false;
        if ($maxLen !== null && mb_strlen($s, 'UTF-8') > $maxLen) return false;
        return true;
    }
}
