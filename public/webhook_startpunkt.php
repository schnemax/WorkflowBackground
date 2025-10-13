<?php

declare(strict_types=1);
header('Content-Type: application/json');


// ===== DIAG (GET ?diag=1 oder POST {"diag":true,"doc":ID}) =====
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$inBody = null;
if ($method === 'POST') {
  $raw = file_get_contents('php://input');
  $inBody = json_decode($raw ?: 'null', true);
}
$wantDiag = isset($_GET['diag']) || (!empty($inBody['diag']));

if ($wantDiag) {
  $u = rtrim(getenv('PAPERLESS_URL') ?: '', '/');
  $t = getenv('PAPERLESS_TOKEN') ?: '';
  $ctx = stream_context_create(['http' => ['header' => [
    'Authorization: Token ' . $t,
    'Accept: application/json; version=9'
  ]]]);

  $out = [
    'env' => [
      'PAPERLESS_URL' => getenv('PAPERLESS_URL') ?: null,
      'MYSQL_DSN'     => getenv('MYSQL_DSN') ?: null,
      'TZ'            => getenv('TZ') ?: null,
      'have_TOKEN'    => $t !== '',
    ],
  ];
  $out['env']['TOKEN_len'] = strlen(getenv('PAPERLESS_TOKEN') ?: '');
  $out['env']['TOKEN_tail_hex'] = bin2hex(substr((getenv('PAPERLESS_TOKEN') ?: ''), -1));
  $meta = [];
  apiReq('GET', '/api/', null, $meta);

  $metaDoc = [];
  $docProbe = apiReq('GET', '/api/documents/?page_size=1', null, $metaDoc);

  $metaById = [];
  $docIdTest = isset($_GET['doc']) ? (int)$_GET['doc'] : 0;
  $docById = $docIdTest ? apiReq('GET', "/api/documents/$docIdTest/", null, $metaById) : [];

  $out['probe_list_status']  = $metaDoc['status'] ?? null;
  $out['probe_list_location'] = $metaDoc['location'] ?? null;

  $out['probe_doc_status']   = $metaById['status'] ?? null;
  $out['probe_doc_location'] = $metaById['location'] ?? null;
  $out['probe_doc_keys']     = is_array($docById) ? array_keys($docById) : [];

  if (!empty($docById)) {
    foreach (['content', 'raw_text_content', 'original_content', 'text', 'text_content'] as $f) {
      if (!empty($docById[$f]) && is_string($docById[$f])) {
        $out['probe_doc_text_field']   = $f;
        $out['probe_doc_text_len']     = mb_strlen($docById[$f], 'UTF-8');
        $out['probe_doc_text_preview'] = mb_substr($docById[$f], 0, 120, 'UTF-8');
        break;
      }
    }
  }


  $out['api_status']   = $meta['status'] ?? null;
  $out['api_location'] = $meta['location'] ?? null;

  $apiRaw = @file_get_contents($u . '/api/', false, $ctx);
  $out['api_status']    = $http_response_header[0] ?? 'no header';
  $out['api_body_head'] = $apiRaw ? substr($apiRaw, 0, 120) : null;

  $docId = isset($_GET['doc']) ? (int)$_GET['doc'] : (int)($inBody['doc'] ?? 0);
  if ($docId > 0) {
    $doc = json_decode(@file_get_contents($u . "/api/documents/$docId/", false, $ctx) ?: 'null', true) ?: [];
    $keys = is_array($doc) ? array_keys($doc) : [];
    $candidates = ['content', 'raw_text_content', 'original_content', 'text', 'text_content'];
    $foundField = $foundLen = $foundPreview = null;
    foreach ($candidates as $f) {
      if (isset($doc[$f]) && is_string($doc[$f])) {
        $foundField = $f;
        $foundLen = mb_strlen($doc[$f], 'UTF-8');
        $foundPreview = mb_substr($doc[$f], 0, 200, 'UTF-8');
        break;
      }
    }
    $out['doc'] = [
      'id' => $doc['id'] ?? null,
      'title' => $doc['title'] ?? null,
      'keys' => $keys,
      'detected_text_field' => $foundField,
      'detected_len' => $foundLen,
      'detected_preview' => $foundPreview,
    ];
  }

  // einfacher MySQL-Connectivity-Check
  try {
    $pdo = new PDO(getenv('MYSQL_DSN'), getenv('MYSQL_USER'), getenv('MYSQL_PASS'), [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      // Setze feste Timezone für die Session (z. B. UTC)
      PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'"
    ]);
    $out['mysql_connect'] = 'ok';
  } catch (Throwable $e) {
    $out['mysql_connect'] = 'error: ' . $e->getMessage();
  }

  echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  exit;
}
// ===== ENDE DIAG =====

// ====== Begin Mainline Code ======================

/* --- Auth (b) --- */
$secret = envs('APP_WEBHOOK_SECRET', '');
if ($secret && !hash_equals($secret, $_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? '')) bad(401, 'unauthorized');

$in = json_decode(file_get_contents('php://input'), true) ?? [];
$docId = (int)($in['document_id'] ?? 0);
if ($docId <= 0) bad(400, 'missing document_id');

$WF = [
  'RECHNUNG' => [
    'required' => ['invoice_number', 'invoice_amount', 'invoice_date', 'issuer_iban', 'issuer_bic'],
    'set_if_ok' => ['WF:PRUEFEN'],
    'set_if_missing' => ['WF:DATEN_UNVOLLSTAENDIG'],
    'skip_approval' => false,
  ],
  'EINZUG' => [
    'required' => ['invoice_amount', 'invoice_date'], // IBAN/BIC optional bei Einzug (liegt idR beim Mandat)
    'set_if_ok' => ['WF:EINZUG_GEPLANT'],
    'set_if_missing' => ['WF:DATEN_UNVOLLSTAENDIG'],
    'skip_approval' => true,
  ],
  'GUTSCHRIFT' => [
    'required' => ['invoice_number', 'invoice_amount', 'invoice_date'],
    'set_if_ok' => ['WF:PRUEFEN'],
    'set_if_missing' => ['WF:DATEN_UNVOLLSTAENDIG'],
    'skip_approval' => true,
  ],
  'BELEG' => [ // Ersatz-/Bar-/Kassenbeleg
    'required' => ['invoice_date', 'invoice_amount'],
    'set_if_ok' => ['WF:ARCHIVIERT_BEREIT'],
    'set_if_missing' => ['WF:DATEN_UNVOLLSTAENDIG'],
    'skip_approval' => true,
  ],
];


$cfId = ensureCustomFieldId('doc_type', 'string');
if ($cfId) {
  apiReq('POST', '/api/custom_fields/set_value/', [
    'document' => $docId,
    'field'    => $cfId,
    'value'    => $docType
  ], $meta);
}

/* --- (b) DMS-Doc holen (Retry bis content da ist) --- */
// --- Dokument laden mit Backoff bis Text vorhanden ist ---

$deadline = microtime(true) + 90;  // bis 90s warten
$delayMs  = 250;
$created = null;
$corrName = null;
$docJson = [];
$content = '';

do {
  $meta = [];
  $docJson = apiReq('GET', "/api/documents/$docId/", null, $meta);

  // Feld automatisch erkennen (bei dir ist es "content")
  foreach (['content', 'raw_text_content', 'original_content', 'text', 'text_content'] as $f) {
    if (!empty($docJson[$f]) && is_string($docJson[$f])) {
      $content = $docJson[$f];
      break;
    }
  }
  if ($content !== '') break;

  usleep($delayMs * 1000);
  $delayMs = min($delayMs * 2, 2000);
} while (microtime(true) < $deadline);

if ($content === '') {
  echo json_encode(['ok' => false, 'error' => 'no content yet', 'doc_id' => $docId]);
  exit;
}

if ($content === '') bad(200, 'no content yet'); // idempotent, Paperless triggert später erneut

// --- (c) Extraktion (Regex) ---> ab hier steht der content des Dokumentes für die Analyse zur Verfügung

$ex = extract_rules($content);
$issuer   = $corrName ?: null;
$invDate  = $ex['date'] ?? ($created ? substr($created, 0, 10) : null);

/* --- (c) In DEINE MySQL schreiben --- */
$pdo = new PDO(envs('MYSQL_DSN'), envs('MYSQL_USER'), envs('MYSQL_PASS'), [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

$pdo->exec("CREATE TABLE IF NOT EXISTS dms_extract (
  dms_document_id INT PRIMARY KEY,
  invoice_number  VARCHAR(128) NULL,
  invoice_amount  DECIMAL(12,2) NULL,
  issuer_name     VARCHAR(255) NULL,
  invoice_date    DATE NULL,
  issuer_iban     VARCHAR(34) NULL,
  issuer_bic      VARCHAR(11) NULL,
  payment_purpose VARCHAR(512) NULL,
  direct_debit    TINYINT(1) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->prepare("
INSERT INTO dms_extract
 (dms_document_id, invoice_number, invoice_amount, issuer_name, invoice_date, issuer_iban, issuer_bic, payment_purpose, direct_debit)
VALUES (?,?,?,?,?,?,?,?,?)
ON DUPLICATE KEY UPDATE
 invoice_number=VALUES(invoice_number),
 invoice_amount=VALUES(invoice_amount),
 issuer_name=VALUES(issuer_name),
 invoice_date=VALUES(invoice_date),
 issuer_iban=VALUES(issuer_iban),
 issuer_bic=VALUES(issuer_bic),
 payment_purpose=VALUES(payment_purpose),
 direct_debit=VALUES(direct_debit),
 updated_at=NOW()
")->execute([
  $docId,
  $ex['inv'] ?? null,
  $ex['amt'] ?? null,
  $issuer,
  $invDate,
  $ex['iban'] ?? null,
  $ex['bic'] ?? null,
  $ex['pur'] ?? null,
  !empty($ex['dd']) ? 1 : 0
]);

/* --- (d) Optional: Tags abhängig von Mussfeldern setzen --- */
$must = [
  'invoice_number' => $ex['inv'] ?? null,
  'invoice_amount' => $ex['amt'] ?? null,
  'invoice_date'   => $invDate,
  'issuer_iban'    => $ex['iban'] ?? null,
  'issuer_bic'     => $ex['bic'] ?? null,
];

$missing = array_keys(array_filter($must, fn($v) => $v === null || $v === '' || $v === 0.0));

$tagIncomplete = envs('WF_TAG_INCOMPLETE', 'WF:DATEN_UNVOLLSTAENDIG');
$tagReady      = envs('WF_TAG_READY', 'WF:PRUEFEN');
$targetName    = $missing ? $tagIncomplete : $tagReady;
$targetId      = ensureTagId($targetName);

if (filter_var(envs('WF_TAG_CLEANUP', 'false'), FILTER_VALIDATE_BOOLEAN)) {
  $old = getCurrentWfTagIds($docId);
  $old = array_values(array_filter($old, fn($id) => $id !== $targetId));
  if ($old) bulkEdit($docId, 'remove_tags', ['remove_tags' => $old]);
}

if ($targetId) bulkEdit($docId, 'add_tags', ['add_tags' => [$targetId]]);

echo json_encode(['ok' => true, 'document_id' => $docId, 'missing' => $missing]);

// End of mainline code






// =========== envs -> get environment variables based on key value
function envs(string $k, ?string $d = null)
{
  $v = getenv($k);
  return $v === false ? $d : $v;
}

// =========== bad --> send error code back to caller and exit logic
function bad(int $code, string $msg)
{
  http_response_code($code);
  echo json_encode(['ok' => false, 'error' => $msg]);
  exit;
}


// =========== apiReq -> Kommunikation mit der paperless API
function apiReq(string $method, string $path, ?array $json = null, array &$respMeta = []): array
{
  $base = rtrim(getenv('PAPERLESS_URL') ?: '', '/');
  $tok  = trim(getenv('PAPERLESS_TOKEN') ?: '');
  $url  = $base . $path;

  // Versuche 1: mit API-Version
  $attempts = [
    ['hdr' => ['Authorization: Token ' . $tok, 'Accept: application/json; version=9'], 'url' => $url],
    // Versuche 2: ohne Version
    ['hdr' => ['Authorization: Token ' . $tok, 'Accept: application/json'], 'url' => $url],
    // Versuche 3: Query-Flag zwingt JSON
    ['hdr' => ['Authorization: Token ' . $tok, 'Accept: application/json'], 'url' => $url . (str_contains($url, '?') ? '&' : '?') . 'format=json'],
  ];

  foreach ($attempts as $i => $att) {
    $ch = curl_init($att['url']);
    $hdrs = $att['hdr'];
    if ($json !== null) {
      $hdrs[] = 'Content-Type: application/json';
      curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json, JSON_UNESCAPED_UNICODE));
    }
    curl_setopt_array($ch, [
      CURLOPT_CUSTOMREQUEST  => $method,
      CURLOPT_HTTPHEADER     => $hdrs,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HEADER         => true,
      CURLOPT_TIMEOUT        => 15,
      CURLOPT_FOLLOWLOCATION => false,
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
      $respMeta = ['curl_error' => curl_error($ch)];
      curl_close($ch);
      return [];
    }
    $status  = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hdrSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $body    = substr($raw, $hdrSize);
    $loc     = null;
    foreach (explode("\r\n", substr($raw, 0, $hdrSize)) as $line) if (stripos($line, 'Location:') === 0) {
      $loc = trim(substr($line, 9));
      break;
    }
    curl_close($ch);

    // 200 -> ok; 302 root-schema o.ä. -> probiere nächste Variante; 406 -> probiere nächste Variante
    if ($status === 200) {
      $respMeta = ['status' => 200, 'location' => $loc, 'attempt' => $i + 1];
      $data = json_decode($body, true);
      return is_array($data) ? $data : [];
    }
    // wenn es ein konkreter Endpoint ist (nicht /api/) und 302 auf Login zeigt, brich ab:
    if ($status === 302 && $loc && str_contains($loc, '/accounts/login')) {
      $respMeta = ['status' => $status, 'location' => $loc, 'attempt' => $i + 1];
      return [];
    }

    // sonst nächsten Versuch probieren
    $respMeta = ['status' => $status, 'location' => $loc, 'attempt' => $i + 1];
  }
  return [];
}

// ============== ensureTagID ->
function ensureTagId(string $name): ?int
{
  static $cache = [];
  if (isset($cache[$name])) return $cache[$name];
  $q = apiReq('GET', '/api/tags/?page_size=1&name__iexact=' . urlencode($name));
  if (!empty($q['results'][0]['id'])) return $cache[$name] = (int)$q['results'][0]['id'];
  $c = apiReq('POST', '/api/tags/', ['name' => $name]);
  return !empty($c['id']) ? $cache[$name] = (int)$c['id'] : null;
}

function bulkEdit(int $docId, string $method, array $params): void
{
  apiReq('POST', '/api/documents/bulk_edit/', [
    'documents' => [$docId],
    'method' => $method,
    'parameters' => $params
  ]);
}

// =============== ermitteln der aktuell gesetzten Workflow Tags ========================
function getCurrentWfTagIds(int $docId): array
{
  $doc = apiReq('GET', "/api/documents/$docId/");
  $tagIds = array_map('intval', $doc['tags'] ?? []);
  if (!$tagIds) return [];
  $out = [];
  foreach ($tagIds as $tid) {
    $t = apiReq('GET', "/api/tags/$tid/");
    if (!empty($t['name']) && str_starts_with($t['name'], 'WF:')) $out[] = $tid;
  }
  return $out;
}

// ================ ermitteln des Dokumenttypes ========================================
function determineDocType(array $docJson, string $content): string
{
  // 1) DT:-Tags (z. B. DT:RECHNUNG, DT:EINZUG, DT:GUTSCHRIFT, DT:BELEG)
  if (!empty($docJson['tags'])) {
    foreach ($docJson['tags'] as $tid) {
      $t = apiReq('GET', "/api/tags/$tid/");
      if (!empty($t['name']) && str_starts_with($t['name'], 'DT:')) {
        return strtoupper(substr($t['name'], 3));
      }
    }
  }
  // 2) Custom Field "doc_type"
  if (!empty($docJson['custom_fields'])) {
    foreach ($docJson['custom_fields'] as $cf) {
      if (($cf['field']['name'] ?? '') === 'doc_type' && !empty($cf['value'])) {
        return strtoupper((string)$cf['value']);
      }
    }
  }
  // 3) Heuristik
  $tU = mb_strtoupper($content, 'UTF-8');
  if (preg_match('/SEPA.*LASTSCHRIFT|EINZUGSERM(Ä|AE)CHTIGUNG|MANDATSREFERENZ/ui', $tU)) return 'EINZUG';
  if (preg_match('/GUTSCHRIFT|CREDIT\s*NOTE/ui', $tU)) return 'GUTSCHRIFT';
  if (preg_match('/ERSATZBELEG|BARBELEG|KASSENBELEG/ui', $tU)) return 'BELEG';
  return 'RECHNUNG';
}

function validateByType(string $type, array $ex, ?string $invDate): array
{
  $req = $GLOBALS['WF'][$type]['required'] ?? [];
  $map = [
    'invoice_number' => $ex['inv'] ?? null,
    'invoice_amount' => $ex['amt'] ?? null,
    'invoice_date'   => $invDate,
    'issuer_iban'    => $ex['iban'] ?? null,
    'issuer_bic'     => $ex['bic'] ?? null,
  ];
  $missing = [];
  foreach ($req as $k) {
    if (empty($map[$k]) && $map[$k] !== 0 && $map[$k] !== '0') $missing[] = $k;
  }
  return [$missing, $map];
}

function syncWorkflowTags(int $docId, string $type, array $missing): void
{
  $cfg = $GLOBALS['WF'][$type] ?? null;
  if (!$cfg) return;
  $targetNames = $missing ? ($cfg['set_if_missing'] ?? []) : ($cfg['set_if_ok'] ?? []);
  $targetIds = array_values(array_filter(array_map('ensureTagId', $targetNames)));
  $existingWf = getCurrentWfTagIds($docId);
  $toRemove = array_diff($existingWf, $targetIds);
  if ($toRemove) bulkEdit($docId, 'remove_tags', ['remove_tags' => array_values($toRemove)]);
  $toAdd = array_diff($targetIds, $existingWf);
  if ($toAdd) bulkEdit($docId, 'add_tags', ['add_tags' => array_values($toAdd)]);
}

function ensureCustomFieldId(string $name, string $type = 'string'): ?int
{
  $q = apiReq('GET', '/api/custom_fields/?page_size=1&name__iexact=' . urlencode($name));
  if (!empty($q['results'][0]['id'])) return (int)$q['results'][0]['id'];
  $c = apiReq('POST', '/api/custom_fields/', ['name' => $name, 'data_type' => $type]);
  return !empty($c['id']) ? (int)$c['id'] : null;
}


function safeAmount(?float $amt): ?string
{
  return $amt === null ? null : number_format($amt, 2, ',', '.') . ' €';
}

function parseIsoDate(?string $d): ?string
{
  return $d ? date('Y-m-d', strtotime($d)) : null;
}

function generateTitle(string $type, array $docJson, array $ex, ?string $invDate): string
{
  $issuer = $docJson['correspondent__name'] ?? $docJson['correspondent_name'] ?? $docJson['correspondent']['name'] ?? null;
  // Fallback: grob aus Content-Headline
  if (!$issuer && !empty($docJson['title'])) $issuer = $docJson['title'];
  $parts = [];
  $parts[] = $type;                                       // Typ
  if ($issuer) $parts[] = trim($issuer);
  if ($invDate = parseIsoDate($invDate)) $parts[] = $invDate;
  if ($ex['amt'] ?? null) $parts[] = safeAmount($ex['amt']);
  if ($ex['inv'] ?? null) $parts[] = 'ReNr ' . $ex['inv'];
  return implode(' · ', array_filter($parts));
}

function updateTitle(int $docId, string $newTitle, array $docJson): void
{
  $old = (string)($docJson['title'] ?? '');
  if ($old === $newTitle || $newTitle === '') return;
  // Optional: verhindere Überschreiben von manuell gesetzten Titeln
  if (!empty($docJson['user_can_change']) && $docJson['user_can_change'] === false) {
    // dann lieber nicht überschreiben
    return;
  }
  apiReq('PATCH', "/api/documents/$docId/", ['title' => $newTitle], $meta);
}

function extract_rules_old_regex(string $t): array
{
  $tU = strtoupper($t);
  $iban = null;
  if (preg_match('/\b([A-Z]{2}\d{2}[A-Z0-9]{10,30})\b/u', str_replace(' ', '', $tU), $m)) $iban = $m[1];
  $bic = null;
  if (preg_match('/\b([A-Z]{4}[A-Z]{2}[A-Z0-9]{2}([A-Z0-9]{3})?)\b/u', $tU, $m)) $bic = $m[1];
  $amt = null;
  if (preg_match('/(\d{1,3}(?:\.\d{3})*(?:,\d{2})|\d+,\d{2})\s*(?:€|EUR)/u', $t, $m)) $amt = (float)str_replace(['. ', ',', '.'], ['', '', '.'], $m[1]);
  $date = null;
  if (preg_match('/\b(\d{4}-\d{2}-\d{2}|\d{1,2}\.\d{1,2}\.\d{2,4})\b/u', $t, $m)) {
    $d = str_replace('.', '-', $m[1]);
    $date = date('Y-m-d', strtotime($d));
  }
  // vorher:
  # /(RECHNUNGS...|INVOICE NO|BELEG...)\s*[:#]?\s*([A-Z0-9\-\/\.]{4,})/

  // nachher (inkl. Slash, Unterstrich, längere Sequenzen):
  if (preg_match('/(RECHNUNGS(?:NUMMER|NR\.?)|INVOICE\s*NO\.?|BELEG(?:NR\.?)?|INV)\s*[:#]?\s*([A-Z0-9][A-Z0-9_\-\/\.]{3,})/ui', $t, $m))
    $inv = trim($m[2], " .:/#");
  else if (preg_match('/\bINV[\/_\-]([0-9]{6,})\b/ui', $t, $m))
    $inv = $m[1];

  $pur = null;
  if (preg_match('/VERWENDUNGSZWECK\s*[:\-]?\s*(.+)/ui', $t, $m)) $pur = trim(mb_substr($m[1], 0, 200));
  $dd = (bool)preg_match('/SEPA.*LASTSCHRIFT|EINZUGSERM(Ä|AE)CHTIGUNG|MANDATSREFERENZ|GL(Ä|AE)UBIGER-IDENTIFIKATIONSNUMMER/ui', $t);
  return compact('inv', 'amt', 'date', 'iban', 'bic', 'pur', 'dd');
}



/**
 * KI-Extraktion für Paperless-Content
 * - Drop-in Ersatz für extract_rules($content)
 * - PHP 7.4+ empfohlen
 */

const OPENAI_MODEL = 'gpt-4o-mini'; // ggf. 'gpt-4.1-mini' für noch robustere Extraktion
const OPENAI_API   = 'https://api.openai.com/v1/chat/completions';

function extract_rules(string $content, ?string $doctype = null, array $allowedDoctypes = []): array {
    // Falls Doctypes eingeschränkt sind
    if ($doctype !== null && $allowedDoctypes) {
        if (!in_array($doctype, $allowedDoctypes, true)) {
            return []; // leer -> nichts extrahieren
        }
    }

    $prompt = build_prompt($content);

    $json = call_openai_json($prompt);
    if (!$json) {
        // Minimaler Fallback (rein heuristisch, wenn KI mal kein JSON liefert)
        $json = [
            'issuer_name'     => null,
            'invoice_date'    => null,
            'invoice_number'  => null,
            'invoice_amount'  => null,
            'issuer_iban'     => null,
            'issuer_bic'      => null,
            'payment_purpose' => null,
            'direct_debit'    => detect_direct_debit($content),
        ];
    }

    // Normalisieren/Validieren
    $out = [
        'issuer_name'     => nn(trim((string)($json['issuer_name'] ?? ''))),
        'invoice_date'    => normalize_date($json['invoice_date'] ?? null),   // YYYY-MM-DD oder null
        'invoice_number'  => nn(trim((string)($json['invoice_number'] ?? ''))),
        'invoice_amount'  => normalize_amount($json['invoice_amount'] ?? null), // "1234.56" oder null
        'issuer_iban'     => normalize_iban($json['issuer_iban'] ?? null),
        'issuer_bic'      => normalize_bic($json['issuer_bic'] ?? null),
        'payment_purpose' => nn(trim((string)($json['payment_purpose'] ?? ''))),
        'direct_debit'    => normalize_bool($json['direct_debit'] ?? detect_direct_debit($content)),
    ];

    return $out;
}

function build_prompt(string $content): string {
    // Schlanker, deterministischer Prompt mit striktem JSON (keine Prosa).
    return <<<PROMPT
Du extrahierst Felder aus deutschem Rechnungstext. Antworte AUSSCHLIESSLICH mit JSON, ohne Erklärungen.
Felder und Regeln:
- issuer_name: Name/Firma des Rechnungsstellers (String).
- invoice_date: Rechnungsdatum im ISO-Format YYYY-MM-DD. Falls mehrere Daten: das Rechnungsdatum.
- invoice_number: die eindeutige Rechnungsnummer (String).
- invoice_amount: Gesamtbetrag BRUTTO als Zahl mit Punkt als Dezimaltrenner (z. B. 1234.56). Ohne Währungssymbol.
- issuer_iban: IBAN des Rechnungsstellers (oder "" wenn nicht vorhanden/unsicher).
- issuer_bic: BIC des Rechnungsstellers (oder "" wenn nicht vorhanden/unsicher).
- payment_purpose: Verwendungszweck/Zahlungszweck, falls erkennbar (String, sonst "").
- direct_debit: true, wenn SEPA-Lastschrift/Einzug vereinbart ist; sonst false.

Wenn du etwas nicht sicher findest, setze es auf "" (bei Strings) oder null (bei Zahlen/Datum) bzw. false (bei Booleans).

Dokumentinhalt:
-----
{$content}
-----
Erzeuge genau dieses JSON-Schema:
{
  "issuer_name": "string",
  "invoice_date": "YYYY-MM-DD or null",
  "invoice_number": "string",
  "invoice_amount": 1234.56 or null,
  "issuer_iban": "string",
  "issuer_bic": "string",
  "payment_purpose": "string",
  "direct_debit": true/false
}
PROMPT;
}

function call_openai_json(string $prompt): ?array {
    $apiKey = getenv('OPENAI_API_KEY') ?: '';
    if ($apiKey === '') {
        // Kein Key konfiguriert
        return null;
    }

    $payload = [
        'model' => OPENAI_MODEL,
        'temperature' => 0.0,
        'messages' => [
            ['role' => 'system', 'content' => 'You are a strict JSON extraction engine. Output only valid JSON.'],
            ['role' => 'user',   'content' => $prompt],
        ],
        // Einfache JSON-Ansage; für harte Validierung könnte man "response_format": ["type"=>"json_object"] nutzen, falls verfügbar
    ];

    $ch = curl_init(OPENAI_API);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 60,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false || $err) {
        return null;
    }

    $data = json_decode($resp, true);
    $text = $data['choices'][0]['message']['content'] ?? '';
    if (!$text) return null;

    // Versuche direktes JSON zu parsen; falls KI Backticks liefert, entfernen
    $text = trim($text);
    $text = preg_replace('/^```json\s*|\s*```$/', '', $text);
    $decoded = json_decode($text, true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        // Minimaler Versuch: JSON aus Text "herausschneiden"
        if (preg_match('/\{.*\}/s', $text, $m)) {
            $decoded = json_decode($m[0], true);
        }
    }
    return (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : null;
}

function normalize_bool($v): int {
    if (is_bool($v)) return $v ? 1 : 0;
    $s = strtolower((string)$v);
    return (in_array($s, ['1','true','yes','ja'], true)) ? 1 : 0;
}

function detect_direct_debit(string $content): int {
    $s = mb_strtolower($content, 'UTF-8');
    $hits = [
        'sepa-lastschrift', 'sepa lastschrift', 'lastschrift', 'einzugsermächtigung',
        'sepa-mandat', 'mandatsreferenz', 'direct debit'
    ];
    foreach ($hits as $h) {
        if (mb_strpos($s, $h) !== false) return 1;
    }
    return 0;
}

function normalize_amount($val): ?string {
    if ($val === null || $val === '') return null;
    $s = trim((string)$val);
    // entferne Währung/Symbole/Spaces
    $s = str_replace(['€','EUR','eur',' '], '', $s);
    // Komma zu Punkt, aber vorsichtig mit Tausenderpunkten:
    // Beispiel "1.234,56" -> "1234.56"
    $s = preg_replace('/\.(?=.*\.)/', '', $s); // alle Punkte außer evtl. letzter entfernen
    $s = str_replace(',', '.', $s);
    if (!is_numeric($s)) return null;
    return number_format((float)$s, 2, '.', '');
}

function normalize_date($v): ?string {
    if (!$v) return null;
    $v = trim((string)$v);
    // Versuche verschiedene deutsche Formate
    $candidates = [$v];
    // Ersetze z. B. 31.01.2024
    if (preg_match('/^\d{1,2}\.\d{1,2}\.\d{2,4}$/', $v)) {
        [$d,$m,$y] = preg_split('/\./', $v);
        $y = (int)$y < 100 ? (2000 + (int)$y) : (int)$y;
        $candidates[] = sprintf('%04d-%02d-%02d', $y, (int)$m, (int)$d);
    }
    foreach ($candidates as $c) {
        $ts = strtotime($c);
        if ($ts !== false) {
            return date('Y-m-d', $ts);
        }
    }
    return null;
}

function normalize_bic($bic): ?string {
    if (!$bic) return null;
    $bic = strtoupper(preg_replace('/\s+/', '', (string)$bic));
    if (preg_match('/^[A-Z0-9]{8}([A-Z0-9]{3})?$/', $bic)) {
        return $bic;
    }
    return null;
}

function normalize_iban($iban): ?string {
    if (!$iban) return null;
    $iban = strtoupper(preg_replace('/\s+/', '', (string)$iban));
    // Grundprüfung: Länderkennz + Länge + Checksumme
    if (!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{10,30}$/', $iban)) {
        return null;
    }
    // Mod97-Check
    $rearranged = substr($iban, 4) . substr($iban, 0, 4);
    $converted  = '';
    for ($i = 0; $i < strlen($rearranged); $i++) {
        $ch = $rearranged[$i];
        if (ctype_alpha($ch)) {
            $converted .= (ord($ch) - 55); // A=10 ... Z=35
        } else {
            $converted .= $ch;
        }
    }
    // Große Zahl mod 97
    $remainder = 0;
    $len = strlen($converted);
    $pos = 0;
    while ($pos < $len) {
        $chunk = $remainder . substr($converted, $pos, 9);
        $remainder = (int)bcmod($chunk, '97');
        $pos += 9;
    }
    if ($remainder === 1) return $iban;
    return null;
}

/**
 * Speichert/aktualisiert Datensatz in deiner Tabelle (UPSERT).
 * $ex kommt direkt von extract_rules(...)
 */
function save_extract(PDO $pdo, int $dms_document_id, array $ex): void {
    $sql = "INSERT INTO dms_extract
        (dms_document_id, invoice_number, invoice_amount, issuer_name, invoice_date, issuer_iban, issuer_bic, payment_purpose, direct_debit)
        VALUES
        (:id, :inv_no, :amount, :issuer, :inv_date, :iban, :bic, :purpose, :dd)
        ON DUPLICATE KEY UPDATE
          invoice_number = VALUES(invoice_number),
          invoice_amount = VALUES(invoice_amount),
          issuer_name    = VALUES(issuer_name),
          invoice_date   = VALUES(invoice_date),
          issuer_iban    = VALUES(issuer_iban),
          issuer_bic     = VALUES(issuer_bic),
          payment_purpose= VALUES(payment_purpose),
          direct_debit   = VALUES(direct_debit),
          updated_at     = CURRENT_TIMESTAMP";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':id'      => $dms_document_id,
        ':inv_no'  => $ex['invoice_number'] ?? null,
        ':amount'  => $ex['invoice_amount'] ?? null,
        ':issuer'  => $ex['issuer_name'] ?? null,
        ':inv_date'=> $ex['invoice_date'] ?? null,
        ':iban'    => $ex['issuer_iban'] ?? null,
        ':bic'     => $ex['issuer_bic'] ?? null,
        ':purpose' => $ex['payment_purpose'] ?? null,
        ':dd'      => isset($ex['direct_debit']) ? (int)$ex['direct_debit'] : 0,
    ]);
}

function nn(?string $s): ?string {
    $s = trim((string)$s);
    return $s === '' ? null : $s;
}

/* ===== Beispiel-Nutzung im bestehenden Flow =====

$allowed = ['Rechnung','Gutschrift']; // optional
$ex = extract_rules($content, $doctype ?? null, $allowed);
if ($ex) {
    save_extract($pdo, (int)$dms_document_id, $ex);
}

*/
