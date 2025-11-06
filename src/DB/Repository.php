<?php

declare(strict_types=1);

namespace App\DB;

use PDO;
use App\Config;
use DateTimeInterface;
use DOMDocument;
use app\Log;
use DateTimeImmutable;
use DateTimeZone;

final class Repository
{
    private ?PDO $pdo = null;
    // ENV Einstellungen
    private string $kontoSachspende = '1299';
    private ?string $iban = null;
    private ?string $bic = null;
    private string $currency = 'EUR';
    // Tabellen
    private string $tblHdr   = 'camt_hdr';
    private string $tblEntry = 'camt_entry';
    private string $tblKonto = 'konto';

    public function __construct(private Config $cfg)
    {
        $this->pdo = new PDO($cfg->mysqlDsn, $cfg->mysqlUser, $cfg->mysqlPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }



    private function pdo(): PDO
    {
        if ($this->pdo) return $this->pdo;
        $this->pdo = new PDO($this->cfg->mysqlDsn, $this->cfg->mysqlUser, $this->cfg->mysqlPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'"
        ]);

        return $this->pdo;
    }

    // $ex enthält die Benutzerattribute, welche aus KI und Benutzereingabe zusammengemischt sind
    // $exKI enthält die Benutzerattribute, wie sie die KI aus dem Dokument extrahiert hat

    public function upsertExtract(int $docId, array $ex, array $exKI): void
    {
        $sql = "INSERT INTO dms_extract
            (dms_document_id, invoice_number, invoice_amount, issuer_name, invoice_date, issuer_iban, issuer_bic, payment_purpose, direct_debit, konto, 
            ai_invoice_number, ai_invoice_amount, ai_issuer_name, ai_invoice_date, ai_issuer_iban, ai_issuer_bic, ai_payment_purpose, ai_direct_debit, ai_konto)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
              invoice_number=VALUES(invoice_number),
              invoice_amount=VALUES(invoice_amount),
              issuer_name=VALUES(issuer_name),
              invoice_date=VALUES(invoice_date),
              issuer_iban=VALUES(issuer_iban),
              issuer_bic=VALUES(issuer_bic),
              payment_purpose=VALUES(payment_purpose),
              direct_debit=VALUES(direct_debit),
              konto=VALUES(konto),
              ai_invoice_number=VALUES(ai_invoice_number),
              ai_invoice_amount=VALUES(ai_invoice_amount),
              ai_issuer_name=VALUES(ai_issuer_name),
              ai_invoice_date=VALUES(ai_invoice_date),
              ai_issuer_iban=VALUES(ai_issuer_iban),
              ai_issuer_bic=VALUES(ai_issuer_bic),
              ai_payment_purpose=VALUES(ai_payment_purpose),
              ai_direct_debit=VALUES(ai_direct_debit),
              ai_konto=VALUES(konto),
              updated_at=NOW()";
        $this->pdo()->prepare($sql)->execute([
            $docId,
            $ex['invoice_number'] ?? null,
            $ex['invoice_amount'] ?? null,
            $ex['issuer_name'] ?? null,
            $ex['invoice_date'] ?? null,
            $ex['issuer_iban'] ?? null,
            $ex['issuer_bic'] ?? null,
            $ex['payment_purpose'] ?? null,
            $ex['direct_debit']  ? (int)(bool)$ex['direct_debit'] : null,
            $ex['konto'] ?? null,
            $exKI['invoice_number'] ?? null,
            $exKI['invoice_amount'] ?? null,
            $exKI['issuer_name'] ?? null,
            $exKI['invoice_date'] ?? null,
            $exKI['issuer_iban'] ?? null,
            $exKI['issuer_bic'] ?? null,
            $exKI['payment_purpose'] ?? null,
            $exKI['direct_debit'] ? (int)(bool)$ex['direct_debit'] : null,
            $exKI['konto'] ?? null,

        ]);
    }

    // nicht benutzt -> die Installation muss dafür sorgen, dass diese Tabellen in SQL
    // vorhanden sind
    private function ensureWfTables(): void
    {
        // wf_jobs
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS wf_jobs (
              document_id    INT PRIMARY KEY,
              state          VARCHAR(64) NOT NULL,
              assignee_email VARCHAR(255) NULL,
              approver_email VARCHAR(255) NULL,
              next_action_at DATETIME NULL,
              last_error     TEXT NULL,
              created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // wf_history -> wird aktuell nicht benutzt
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS wf_history (
              id          BIGINT PRIMARY KEY AUTO_INCREMENT,
              document_id INT NOT NULL,
              ts          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              event       VARCHAR(64) NOT NULL,
              payload     JSON NULL,
              INDEX (document_id),
              INDEX (ts)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    /** Aktuellen Workflow-Status eines Dokuments holen (oder null). */
    public function wfGetState(int $documentId): ?string
    {
        $stmt = $this->pdo->prepare("SELECT state FROM wf_jobs WHERE document_id = ?");
        $stmt->execute([$documentId]);
        $val = $stmt->fetchColumn();
        return $val === false ? null : (string) $val;
    }

    /**
     * Status setzen (Upsert). Optional: next_action_at / assignee / approver / last_error.
     * $extra: ['next_action_at'=>DateTimeInterface|string|null, 'assignee_email'=>?, 'approver_email'=>?, 'last_error'=>?]
     */
    public function wfSetState(int $documentId, string $state, string $title, string $document_type,  array $extra = []): void
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        $assignee = $extra['assignee_email'] ?? null;
        $approver = $extra['approver_email'] ?? null;
        $nextAt   = $extra['next_action_at'] ?? null;
        $error    = $extra['last_error'] ?? null;

        if ($nextAt instanceof DateTimeInterface) {
            $nextAt = $nextAt->format('Y-m-d H:i:s');
        } elseif (is_string($nextAt) && $nextAt !== '') {
            // lass String durch
        } else {
            $nextAt = null;
        }


        if ($title === null || $title === '') {
            $sql = "
              INSERT INTO wf_jobs (document_id, state, assignee_email, approver_email, next_action_at, last_error, document_type)
              VALUES (:id,:st,:as,:ap,:na,:err,:document_type)
              ON DUPLICATE KEY UPDATE
                state=VALUES(state),
                assignee_email=VALUES(assignee_email),
                approver_email=VALUES(approver_email),
                next_action_at=VALUES(next_action_at),
                last_error=VALUES(last_error),
                document_type=VALUES(document_type),
                updated_at=CURRENT_TIMESTAMP
            ";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':id' => $documentId,
                ':st' => $state,
                ':as' => $assignee,
                ':ap' => $approver,
                ':na' => $nextAt,
                ':err' => $error,
                ':document_type' => $document_type,
            ]);
        } else {
            $sql = "
              INSERT INTO wf_jobs (document_id, state, assignee_email, approver_email, next_action_at, last_error, title, document_type)
              VALUES (:id,:st,:as,:ap,:na,:err, :title, :document_type)
              ON DUPLICATE KEY UPDATE
                state=VALUES(state),
                assignee_email=VALUES(assignee_email),
                approver_email=VALUES(approver_email),
                next_action_at=VALUES(next_action_at),
                last_error=VALUES(last_error),
                title=VALUES(title),
                document_type=VALUES(document_type),
                updated_at=CURRENT_TIMESTAMP
            ";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':id' => $documentId,
                ':st' => $state,
                ':as' => $assignee,
                ':ap' => $approver,
                ':na' => $nextAt,
                ':err' => $error,
                ':title' => $title,
                ':document_type' => $document_type,
            ]);
        }
    }

    /** History-Event protokollieren (optional, aber praktisch für Audits). */
    public function wfAddHistory(int $documentId, string $event, ?array $payload = null): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO wf_history (document_id, event, payload)
            VALUES (?, ?, :payload)
        ");
        $stmt->bindValue(1, $documentId, PDO::PARAM_INT);
        $stmt->bindValue(2, $event, PDO::PARAM_STR);
        $stmt->bindValue(':payload', $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null, $payload ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->execute();
    }
    public function wfHistory(int $limit = 20): array
    {
        $st = $this->pdo->prepare("SELECT * FROM wf_history ORDER BY ts DESC LIMIT ?");
        $st->bindValue(1, $limit, \PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }
    public function getExtract(int $docId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM dms_extract WHERE dms_document_id = ? LIMIT 1');
        $stmt->execute([$docId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        //Log::j('DEBUG', 'getExtract', ['stmt' => $stmt, 'docid' => $docId, 'row' =>$row]);
        return $row ?: null;
    }

    // DLookup function -> similar to VBA function DLOOKUP
    // Example: $replyto = DLookup("MailCopyAddress","parameter","id='L'");
    public function DLookup($field, $table, $criteria)
    {
        try {
            $query = "SELECT $field FROM $table WHERE $criteria LIMIT 1";
            $stmt = $this->pdo->prepare($query);
            $stmt->execute();
            $result = $stmt->fetchColumn();
            return $result !== false ? $result : null;
        } catch (\PDOException $e) {
            return null;
        }
    }
    // function get_variable_value -> get a variable value from common t_variable table
    // Example: $replyto = DLookup("MailCopyAddress","parameter","id='L'");
    public function get_variable_value($varname)
    {
        try {
            $stmt = $this->pdo->prepare("SELECT varcontent FROM t_variable WHERE varname = ? LIMIT 1");
            $stmt->bindValue(1, $varname, PDO::PARAM_STR);
            $stmt->execute();
            $result = $stmt->fetchColumn();
            //Log::j('DEBUG', 'get_variable', ['stmt' => $stmt, 'result' => $result, 'varname' => $varname]);
            return $result !== false ? $result : null;
        } catch (\PDOException $e) {
            Log::j('DEBUG', 'get_variable', ['error' => $e]);
            return null;
        }
    }
    // function get_variable_value -> get a variable value from common t_variable table
    // Example: $replyto = DLookup("MailCopyAddress","parameter","id='L'");
    public function check_konto($konto_to_check): ?bool
    {
        try {
            $stmt = $this->pdo->prepare("SELECT konto FROM konto WHERE jahr = ? and konto = ? and UberweisungRelevant != 0 LIMIT 1");
            $jahr = date('Y');
            $stmt->bindvalue(1, $jahr, PDO::PARAM_STR);
            $stmt->bindValue(2, $konto_to_check, PDO::PARAM_STR);
            $stmt->execute();
            $result = $stmt->fetchColumn();
            Log::j('DEBUG', 'check_konto', ['stmt' => $stmt, 'result' => $result, 'varname' => $konto_to_check]);
            if ($result) {
                return true;
            } else {
                return false;
            };
        } catch (\PDOException $e) {
            Log::j('DEBUG', 'get_variable', ['error' => $e]);
            return false;
        }
    }

    // function to delete entry from dms_extract table
    public function deleteExtract($docId): void
    {
        try {
            $stmt = $this->pdo->prepare("DELETE FROM dms_extract WHERE dms_document_id = ?");
            $stmt->bindValue(1, $docId, PDO::PARAM_INT);
            $stmt->execute();
        } catch (\PDOException $e) {
            Log::j('DEBUG', 'delete_extract', ['error' => $e]);
        }
    }

    // function to delete entry from wf_job table
    public function deleteWfJob($docId): void
    {
        try {
            $stmt = $this->pdo->prepare("DELETE FROM wf_jobs WHERE dms_document_id = ?");
            $stmt->bindValue(1, $docId, PDO::PARAM_INT);
            $stmt->execute();
        } catch (\PDOException $e) {
            Log::j('DEBUG', 'delete_wf_job', ['error' => $e]);
        }
    }

    // function to find entry in dkbd table
    public function find_dkbd_entry($dkbid, $lfdnr): ?array
    {
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM dkbd WHERE dkbid = ? and lfdnr = ? LIMIT 1");
            $stmt->bindValue(1, $dkbid, PDO::PARAM_INT);
            $stmt->bindValue(2, $lfdnr, PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            //Log::j('DEBUG', 'find_dkbd_entry', ['stmt' => $stmt, 'dkbid' => $dkbid, 'lfdnr' =>$lfdnr, 'row' =>$row]);
            return $row ?: null;
        } catch (\PDOException $e) {
            Log::j('DEBUG', 'find_dkbd_entry', ['error' => $e]);
            return null;
        }
    }

    public function link_dok_to_dkbd($dokid, $dkbid, $lfdnr): ?bool
    {
        try {
            $stmt = $this->pdo->prepare("UPDATE dkbd SET dmsdokid = ? WHERE dkbid = ? AND lfdnr = ? LIMIT 1");
            $stmt->bindValue(1, $dokid, PDO::PARAM_INT);
            $stmt->bindValue(2, $dkbid, PDO::PARAM_INT);
            $stmt->bindValue(3, $lfdnr, PDO::PARAM_INT);
            $stmt->execute();
            return true;
        } catch (\PDOException $e) {
            Log::j('DEBUG', 'link_dok_to_dkbd', ['error' => $e]);
            return false;
        }
    }
    /**
     * Nur History schreiben (z. B. vom Poller).
     * @param int         $docId
     * @param string|null $from
     * @param string      $to
     * @param string      $by   default '-intern-'
     * @param \DateTimeImmutable|null $at  default: jetzt in $this->tz
     */
    public function logWfHistory(
        int $docId,
        ?string $from,
        string $to,
        string $by = '-intern-',
        $at = null
    ): bool {
        $at = $at ?? new DateTimeImmutable('now', new DateTimeZone("Europe/Berlin"));
        $stamp = $at->format('Y-m-d H:i:s');

        $sql = "INSERT INTO wf_history
                (document_id, changed_at, from_status, to_status, changed_by)
                VALUES (:id, :at, :from, :to, :by)";

        // Kollisionen (gleiche Sekunde) abfangen: bis zu 3x +1 Sekunde probieren
        for ($i = 0; $i < 3; $i++) {
            try {
                $stmt = $this->pdo->prepare($sql);
                return $stmt->execute([
                    ':id'   => $docId,
                    ':at'   => $stamp,
                    ':from' => $from,
                    ':to'   => $to,
                    ':by'   => mb_substr($by ?: '-intern-', 0, 50),
                ]);
            } catch (\PDOException $e) {
                // 23000 = Duplicate entry (Primärschlüssel-Kollision)
                if ($e->getCode() !== '23000')
                    throw $e;
            }
        }
        return false;
    }



    /**
     * Erzeugt Header + zwei Entries (DBIT/CRDT) für eine Auto-Buchung (Pseudo-Kontoauszug).
     * - Header.id = nextId (MAX(id)+1 aus camt_hdr, FOR UPDATE)
     * - Entry.ID  = nextId (für beide Sätze), Entry.hdr_id existiert in deinem Schema nicht -> nutzen gleiche ID
     * - Beginn/Endsaldo aus Konto-Tabelle (konto=ENV, jahr)
     * - Dok-ID ($docId) wird in camt_hdr.data_reference und camt_entry.dmsdokid geschrieben
     *
     * Erwartete Felder in $ex:
     *   - invoice_amount (string/float, "1234.56")
     *   - invoice_date? (YYYY-MM-DD)
     *   - invoice_number? (string)
     *   - issuer_name? (string)
     *   - payment_purpose? (string)
     */
    public function createAutoBookingPseudoStatement(int $docId, array $ex): int
    {
        $amount = $this->normAmount($ex['invoice_amount'] ?? null);
        if ($amount === null) {
            throw new \InvalidArgumentException('invoice_amount fehlt/ungültig.');
        }

        $jahr     = $this->determineYear($ex['invoice_date'] ?? date("Y") ??  null);
        $konto    = $this->get_variable_value('CAMT_Sachspende') ?? $this->kontoSachspende;
        $valDate  = $ex['invoice_date'] ?? null;
        $nowTs    = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $today    = (new \DateTimeImmutable())->format('Y-m-d');

        // Texte
        $ustrd = $ex['payment_purpose'] ?? (
            isset($ex['invoice_number']) ? ('Rechnung ' . $ex['invoice_number']) : null
        );
        $addlntryinf = 'Auto-Buchung Pseudo-Kontoauszug';

        // Gegenparteinamen (optional aus Extract)
        $debitorName  = $ex['issuer_name'] ?? null;
        $creditorName = $ex['issuer_name'] ?? null;

        // referenz IDs für acctsvcrref
        [$refDebit, $refCredit] = $this->buildSachspendeRefs($ex);

        // Transaktion
        $this->pdo->beginTransaction();
        try {
            // nextId aus camt_hdr
            $nextId = (int)$this->pdo
                ->query("SELECT COALESCE(MAX(id),0)+1 AS nid FROM {$this->tblHdr} FOR UPDATE")
                ->fetch(\PDO::FETCH_ASSOC)['nid'];

            // Beginn-Saldo
            $stmtSaldo = $this->pdo->prepare(
                "SELECT saldo FROM {$this->tblKonto} WHERE konto = :k AND jahr = :y LIMIT 1"
            );
            $stmtSaldo->execute([':k' => $konto, ':y' => $jahr]);
            $rowSaldo = $stmtSaldo->fetch(\PDO::FETCH_ASSOC);
            $beginnSaldo = $rowSaldo ? $this->normAmount($rowSaldo['saldo']) : '0.00';

            // End-Saldo
            $endeSaldo = $this->bcAdd($beginnSaldo, $amount);

            // ---------- HEADER ----------
            $sqlHdr = "
                INSERT INTO {$this->tblHdr}
                    (id, msgid, creadate, iban, bic, open_balance, closing_balance, open_currency,
                     status, data_reference, archiviert, konto, valuta_fruehest)
                VALUES
                    (:id, :msgid, :creadate, :iban, :bic, :open_balance, :closing_balance, :open_currency,
                     :status, :data_reference, :archiviert, :konto, :valuta_fruehest)
            ";
            $stmtHdr = $this->pdo->prepare($sqlHdr);
            $stmtHdr->execute([
                ':id'               => $nextId,
                ':msgid'            => 'Sachspende-' . $nextId,                 // frei wählbar/ableitbar
                ':creadate'         => $nowTs,
                ':iban'             => $this->iban ?? 'N/A',
                ':bic'              => $this->bic ?? 'N/A',
                ':open_balance'     => $beginnSaldo,
                ':closing_balance'  => $endeSaldo,
                ':open_currency'    => $this->currency ?? 'EUR',
                ':status'           => 'L',                            // L=geladen
                ':data_reference'   => 'automatisch aus Dokument ' . (string)$docId,                 // initiierendes Dokument
                ':archiviert'       => false,                              // b'0'
                ':konto'            => $konto,
                ':valuta_fruehest'  => $valDate,                       // kann NULL sein
            ]);

            // Gemeinsame Entry-Werte
            $common = [
                ':ID'                => $nextId,           // Beide Sätze mit gleicher ID
                ':konto'             => $konto,
                ':amt'               => $amount,
                ':curcy'             => $this->currency,
                ':bookgdt'           => $today,            // Buchungsdatum heute
                ':valdt'             => $valDate,          // Wertstellung lt. ex (kann NULL)
                ':addlntryinf'       => $addlntryinf,
                ':ustrd'             => $ustrd,
                ':debitorname'       => $debitorName,
                ':creditorname'      => $creditorName,
                ':debitoriban'       => null,
                ':creditoriban'      => null,
                ':endtoendid'        => null,
                ':buchungsjahr'      => null,
                ':belegnummer'       => null,
                ':beginnsaldo'       => $beginnSaldo,
                ':endesaldo'         => $endeSaldo,
                ':mglid'             => null,
                ':einzugsammelid'    => null,
                ':gareferenz'        => null,
                ':gebucht'           => false,               // b'0'
                ':eigenbeleg'        => false,               // b'0'
                ':buchungstext_ind'  => null,
                ':suggested_account' => null,
                ':matched_rule_id'   => null,
                ':beitrag_jahr'      => null,
                ':beitrag_rnr'       => null,
                ':ruecklaeufer'      => false,               // b'0'
            ];

            $sqlEntry = "
                INSERT INTO {$this->tblEntry}
                    (ID, acctsvcrref, konto, amt, curcy, bookgdt, valdt, cdtdbtind, addlntryinf, ustrd,
                     debitorname, creditorname, debitoriban, creditoriban, endtoendid, buchungsjahr,
                     belegnummer, gegenkonto, beginnsaldo, endesaldo, mglid, einzugsammelid, gareferenz,
                     dmsdokid, buchungsart, gebucht, eigenbeleg, buchungstext_individuell, suggested_account,
                     matched_rule_id, beitrag_jahr, beitrag_rnr, ruecklaeufer)
                VALUES
                    (:ID, :acctsvcrref, :konto, :amt, :curcy, :bookgdt, :valdt, :cdtdbtind, :addlntryinf, :ustrd,
                     :debitorname, :creditorname, :debitoriban, :creditoriban, :endtoendid, :buchungsjahr,
                     :belegnummer, :gegenkonto, :beginnsaldo, :endesaldo, :mglid, :einzugsammelid, :gareferenz,
                     :dmsdokid, :buchungsart, :gebucht, :eigenbeleg, :buchungstext_ind, :suggested_account,
                     :matched_rule_id, :beitrag_jahr, :beitrag_rnr, :ruecklaeufer)
            ";
            $stmtEntry = $this->pdo->prepare($sqlEntry);

            // --- DEBIT (DBIT) ---
            $paramsDbt = $common + [
                ':acctsvcrref' => $refDebit,
                ':cdtdbtind'   => 'DBIT',
                ':buchungstext_ind' => null,
                ':buchungsart' => 'A',  // A=Ausgabe
                ':dmsdokid'    => $docId,
                ':gegenkonto'  => null,
            ];
            $stmtEntry->execute($paramsDbt);

            // --- CREDIT (CRDT) Spiegel ---
            $paramsCrd = $common + [
                ':acctsvcrref' => $refCredit,
                ':cdtdbtind'   => 'CRDT',
                ':buchungstext_ind' => null,
                ':buchungsart' => 'E',  // E=Einnahme
                ':dmsdokid'    => 0,  // nur im DEBIT der Verweis auf das Dokument
                ':gegenkonto'  => '80500',  // Sachspenden-Erlöse
            ];
            $stmtEntry->execute($paramsCrd);

            $this->pdo->commit();
            return $nextId;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    // --------- Helpers ---------

    private function determineYear(?string $invoiceDate): int
    {
        if ($invoiceDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $invoiceDate)) {
            return (int)substr($invoiceDate, 0, 4);
        }
        return (int)date('Y');
    }

    private function bcAdd(string $a, string $b): string
    {
        return number_format(((float)$a + (float)$b), 2, '.', '');
    }

    private function buildSachspendeRefs(array $ex): array
    {
        $docOrInv = $ex['invoice_number'] ?? ($ex['dms_document_id'] ?? 'NA');
        $date     = isset($ex['invoice_date']) ? str_replace('-', '', $ex['invoice_date']) : '00000000';
        $entropy  = ($ex['invoice_number'] ?? '') . '|' . ($ex['dms_document_id'] ?? '') . '|' . ($ex['invoice_amount'] ?? '');
        $hash4    = strtoupper(substr(sha1($entropy), 0, 4));
        $base     = sprintf('SACHSPENDE-%s-%s-%s', $docOrInv, $date, $hash4);
        return [$base . '-DEBIT', $base . '-CREDIT'];
    }

    private function normAmount(null|string|int|float $raw): ?string
    {
        if ($raw === null || $raw === '') return null;
        $s = is_string($raw) ? trim($raw) : (string)$raw;
        if (preg_match('/^\d{1,3}(\.\d{3})*(,\d{1,2})?$/', $s)) {
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        }
        if (!is_numeric($s)) return null;
        return number_format((float)$s, 2, '.', '');
    }
}
