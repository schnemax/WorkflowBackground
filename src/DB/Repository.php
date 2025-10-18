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

    public function __construct(private Config $cfg)
    {
        $this->pdo = new PDO($cfg->mysqlDsn, $cfg->mysqlUser, $cfg->mysqlPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        //$this->ensureWfTables();
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


        if ($title === '') {
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
}
