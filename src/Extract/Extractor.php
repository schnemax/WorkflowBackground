<?php

declare(strict_types=1);

namespace App\Extract;

use \App\Log;

final class Extractor
{
    /**
     * KI-Extraktion für Paperless-Content
     * - Drop-in Ersatz für extract_rules($content)
     * - PHP 7.4+ empfohlen
     */

    const OPENAI_MODEL = 'gpt-4o-mini'; // ggf. 'gpt-4.1-mini' für noch robustere Extraktion
    const OPENAI_API   = 'https://api.openai.com/v1/chat/completions';

    private static function normalizeDoctype(?string $t): ?string
    {
        if ($t === null) return null;
        //$t = trim(mb_strtolower($t, 'UTF-8'));
        $t = trim($t);
        return $t === '' ? null : $t;
    }

    public static function isAllowedDoctype(?string $doctype, array $allowed): bool
    {
        if (!$doctype || empty($allowed)) return false;
        $dt = self::normalizeDoctype($doctype);
        if (!$dt) return false;

        $allow = array_values(array_filter(array_map(fn($s) => self::normalizeDoctype($s), $allowed)));
        return in_array($dt, $allow, true);
    }


    public function extract_old(string $content, ?string $doctype = null): array
    {
        // Striktes Gate: ohne erlaubte Liste kein Run
        //if (!self::isAllowedDoctype($doctype, $allowedDoctypes)) {
        //    self::log("Extractor: skip (doctype '{$doctype}' not allowed)");
        //    return [];
        //}

        // 1) KI aufrufen
        $json = self::callOpenaiJson($content, debug: false);
        Log::j('DEBUG', 'isAllowed', ['jsonAI' => $json]);
        if (!$json) {
            // Fallback: alles leer/heuristisch
            $json = [
                'issuer_name'     => null,
                'invoice_date'    => null,
                'invoice_number'  => null,
                'invoice_amount'  => null,
                'issuer_iban'     => null,
                'issuer_bic'      => null,
                'payment_purpose' => null,
                'direct_debit'    => self::detect_Direct_Debit($content),
            ];
        }

        // 2) Normalisieren (deine Helfer als private static Methoden)
        $norm = [
            'issuer_name'     => self::nn($json['issuer_name'] ?? ''),
            'invoice_date'    => self::normalize_Date($json['invoice_date'] ?? null),          // YYYY-MM-DD|null
            'invoice_number'  => self::nn($json['invoice_number'] ?? ''),
            'invoice_amount'  => self::normalize_Amount($json['invoice_amount'] ?? null),      // "1234.56"|null
            'issuer_iban'     => self::normalize_Iban($json['issuer_iban'] ?? null),
            'issuer_bic'      => self::normalize_Bic($json['issuer_bic'] ?? null),
            'payment_purpose' => self::nn($json['payment_purpose'] ?? ''),
            'direct_debit'    => self::normalize_Bool($json['direct_debit'] ?? false),         // 0/1 oder bool
        ];
        Log::j('DEBUG', 'isAllowed2', ['norm' => $norm]);
        // 3) Kompat-Map für deinen Controller/Repo
        $compat = [
            'invoice_number'  => $norm['invoice_number'],
            'invoice_amount'  => $norm['invoice_amount'],
            'issuer_iban' => $norm['issuer_iban'],
            'issuer_bic'  => $norm['issuer_bic'],
            'payment_purpose'  => $norm['payment_purpose'],
            'direct_debit'   => $norm['direct_debit'],
            // Bonus: falls du die Lang-Keys im Controller nutzen willst:
            'issuer_name'  => $norm['issuer_name'],
            'invoice_date' => $norm['invoice_date'],
        ];

        // 4) Beides zusammen zurückgeben
        return $norm;
    }

    // neu ausgebildete Funktion extract -> aktuell wird nur der Dokumenttyp Rechnung
    // abgewickelt. Andere Doktypen müssen erst noch definiert werden

    public function extract(string $content, ?string $doctype = null, array $allowed = []): array
    {
        $type = $doctype ?: 'Rechnung';
        if ($allowed && !in_array($type, $allowed, true)) {
            return []; // ignorieren
        }

        $json = $this->call_openai_json_with_prompts($type, $content);
        Log::j('DEBUG', 'json_on_return', ['type' => $type, 'json' => $json]);
        if (!$json) {
            // Fallbacks wie gehabt…
            return [
                'issuer_name' => null,
                'invoice_date' => null,
                'invoice_number' => null,
                'invoice_amount' => null,
                'issuer_iban' => null,
                'issuer_bic' => null,
                'payment_purpose' => null,
                'direct_debit' => $this->detect_direct_debit($content),
            ];
        }

        switch ($doctype) {
            case 'Rechnung' || 'LG_Lohnsteuer' || 'LG_KK || LG_Abrechnung || Barbeleg':
                // 2) Normalisieren (deine Helfer als private static Methoden)
                $norm = [
                    'issuer_name'     => self::nn($json['issuer_name'] ?? ''),
                    'invoice_date'    => self::normalize_Date($json['invoice_date'] ?? null),          // YYYY-MM-DD|null
                    'invoice_number'  => self::nn($json['invoice_number'] ?? ''),
                    'invoice_amount'  => self::normalize_Amount($json['invoice_amount'] ?? null),      // "1234.56"|null
                    'issuer_iban'     => self::normalize_Iban($json['issuer_iban'] ?? null),
                    'issuer_bic'      => self::normalize_Bic($json['issuer_bic'] ?? null),
                    'payment_purpose' => self::nn($json['payment_purpose'] ?? ''),
                    'direct_debit'    => self::normalize_Bool($json['direct_debit'] ?? false),         // 0/1 oder bool
                ];
                Log::j('DEBUG', 'json_norm', ['norm' => $norm]);
                return $norm;
                break;
            default:
                $norm = [
                    'issuer_name'     => self::nn($json['issuer_name'] ?? ''),
                    'invoice_date'    => self::normalize_Date($json['invoice_date'] ?? null),          // YYYY-MM-DD|null
                    'invoice_number'  => self::nn($json['invoice_number'] ?? ''),
                    'invoice_amount'  => self::normalize_Amount($json['invoice_amount'] ?? null),      // "1234.56"|null
                    'issuer_iban'     => self::normalize_Iban($json['issuer_iban'] ?? null),
                    'issuer_bic'      => self::normalize_Bic($json['issuer_bic'] ?? null),
                    'payment_purpose' => self::nn($json['payment_purpose'] ?? ''),
                    'direct_debit'    => self::normalize_Bool($json['direct_debit'] ?? false),         // 0/1 oder bool
                ];
                return $norm;
                break;
        }
        //return $json;
    }


    private static function  build_prompt_rechnung(string $content): string
    {
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

    // In deiner Klasse:
    private static function log(string $msg): void
    {
        // Für Docker ideal: STDERR
        if (@file_put_contents('php://stderr', "[Extractor] $msg\n", FILE_APPEND) === false) {
            error_log("[Extractor] $msg");
        }
    }

    private static function callOpenaiJson(string $content, bool $debug = false): ?array
    {
        $apiKey = self::getApiKey(); // siehe getApiKey() weiter unten
        if (!$apiKey) {
            self::log('OPENAI_API_KEY not set/visible (after all fallbacks).');
            return null;
        }

        // Content ggf. kürzen (zu lange Prompts ⇒ 400/413)
        $truncated = false;
        $maxLen = 60000;
        if (strlen($content) > $maxLen) {
            $content = substr($content, 0, $maxLen);
            $truncated = true;
        }

        $prompt = self::build_Prompt_rechnung($content);

        $payload = [
            'model'            => self::OPENAI_MODEL,          // z. B. 'gpt-4o-mini'
            'temperature'      => 0.0,
            'response_format'  => ['type' => 'json_object'],   // erzwinge JSON
            'messages'         => [
                ['role' => 'system', 'content' => 'You output only valid minified JSON. No prose.'],
                ['role' => 'user',   'content' => $prompt],
            ],
        ];

        $ch = curl_init(self::OPENAI_API); // 'https://api.openai.com/v1/chat/completions'
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HEADER         => true, // <— Header mitliefern
        ]);

        $resp     = curl_exec($ch);
        $curlErr  = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hdrSize  = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($resp === false) {
            self::log('cURL error: ' . $curlErr);
            return null;
        }

        $rawHeaders = substr($resp, 0, $hdrSize);
        $body       = substr($resp, $hdrSize);

        if ($debug) {
            self::log('HTTP ' . $httpCode . '; truncated=' . ($truncated ? 'yes' : 'no'));
            // ein paar nützliche Header herausgreifen
            foreach (['x-request-id', 'x-ratelimit-limit-requests', 'x-ratelimit-remaining-requests', 'content-type'] as $h) {
                if (preg_match('/^' . preg_quote($h, '/') . ':\s*(.+)$/im', $rawHeaders, $m)) {
                    self::log($h . ': ' . trim($m[1]));
                }
            }
            self::log('Body head: ' . mb_substr(trim($body), 0, 600));
        }

        // Fehler-HTTP-Codes sichtbar machen
        if ($httpCode < 200 || $httpCode >= 300) {
            self::log('OpenAI HTTP error ' . $httpCode . ' body head: ' . mb_substr($body, 0, 800));
            // Spezieller Fallback: Manche Modelle unterstützen response_format (noch) nicht
            if (strpos($body, 'response_format') !== false) {
                self::log('Retry without response_format ...');
                unset($payload['response_format']);
                return self::retryOpenAi($apiKey, $payload, $debug);
            }
            return null;
        }

        // Normale Erfolgs-Antwort

        $data = json_decode($body, true);
        if (!is_array($data)) {
            self::log('Invalid JSON envelope from OpenAI.');
            return null;
        }

        $msg  = $data['choices'][0]['message'] ?? null;
        $text = '';
        if (is_array($msg) && isset($msg['content'])) {
            // Chat Completions: content ist ein STRING, der wiederum JSON enthält (dein Fall)
            if (is_string($msg['content'])) {
                $text = $msg['content'];
            }
            // Responses-API (falls du mal wechselst): content kann ein Array aus Blöcken sein
            elseif (is_array($msg['content']) && isset($msg['content'][0]['text'])) {
                $text = (string)$msg['content'][0]['text'];
            }
        }

        $text = trim((string)$text);
        if ($text === '') {
            self::log('Empty message.content from OpenAI.');
            return null;
        }

        // Falls Codefences drin sind -> weg
        $text = preg_replace('/^```json\s*|\s*```$/', '', $text);

        // Manche Modelle packen noch Text drumrum – dann den JSON-Teil herausziehen
        if ($text[0] !== '{') {
            if (preg_match('/\{(?:[^{}]|(?R))*\}/s', $text, $m)) {
                $text = $m[0];
            }
        }

        // Jetzt das eigentliche JSON der Felder dekodieren
        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            self::log('json_decode(content) failed: ' . (function_exists('json_last_error_msg') ? json_last_error_msg() : 'json error'));
            self::log('content head: ' . mb_substr($text, 0, 300));
            return null;
        }

        // Optionales Debug
        self::log('Decoded keys: ' . implode(',', array_keys($decoded)));

        return $decoded;
    }

    // das ist eine Kopie der Funktion callOpenaiJson. Allerdings erwartet diese Funktion
    // im Parameter Content die bereits komplett aufbereitete Frage für AI
    private static function http_post_openai(array $payload, bool $debug = false): ?array
    {
        $apiKey = self::getApiKey(); // siehe getApiKey() weiter unten
        if (!$apiKey) {
            self::log('OPENAI_API_KEY not set/visible (after all fallbacks).');
            return null;
        }

        $ch = curl_init(self::OPENAI_API); // 'https://api.openai.com/v1/chat/completions'
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HEADER         => true, // <— Header mitliefern
        ]);

        $resp     = curl_exec($ch);
        $curlErr  = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hdrSize  = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($resp === false) {
            self::log('cURL error: ' . $curlErr);
            return null;
        }

        $rawHeaders = substr($resp, 0, $hdrSize);
        $body       = substr($resp, $hdrSize);

        if ($debug) {
            self::log('HTTP ' . $httpCode . '; payload=' . ($payload ? 'yes' : 'no'));
            // ein paar nützliche Header herausgreifen
            foreach (['x-request-id', 'x-ratelimit-limit-requests', 'x-ratelimit-remaining-requests', 'content-type'] as $h) {
                if (preg_match('/^' . preg_quote($h, '/') . ':\s*(.+)$/im', $rawHeaders, $m)) {
                    self::log($h . ': ' . trim($m[1]));
                }
            }
            self::log('Body head: ' . mb_substr(trim($body), 0, 600));
        }

        // Fehler-HTTP-Codes sichtbar machen
        if ($httpCode < 200 || $httpCode >= 300) {
            self::log('OpenAI HTTP error ' . $httpCode . ' body head: ' . mb_substr($body, 0, 800));
            // Spezieller Fallback: Manche Modelle unterstützen response_format (noch) nicht
            if (strpos($body, 'response_format') !== false) {
                self::log('Retry without response_format ...');
                unset($payload['response_format']);
                return self::retryOpenAi($apiKey, $payload, $debug);
            }
            return null;
        }

        // Normale Erfolgs-Antwort

        $data = json_decode($body, true);
        if (!is_array($data)) {
            self::log('Invalid JSON envelope from OpenAI.');
            return null;
        }

        $msg  = $data['choices'][0]['message'] ?? null;
        $text = '';
        if (is_array($msg) && isset($msg['content'])) {
            // Chat Completions: content ist ein STRING, der wiederum JSON enthält (dein Fall)
            if (is_string($msg['content'])) {
                $text = $msg['content'];
            }
            // Responses-API (falls du mal wechselst): content kann ein Array aus Blöcken sein
            elseif (is_array($msg['content']) && isset($msg['content'][0]['text'])) {
                $text = (string)$msg['content'][0]['text'];
            }
        }

        $text = trim((string)$text);
        if ($text === '') {
            self::log('Empty message.content from OpenAI.');
            return null;
        }

        // Falls Codefences drin sind -> weg
        $text = preg_replace('/^```json\s*|\s*```$/', '', $text);

        // Manche Modelle packen noch Text drumrum – dann den JSON-Teil herausziehen
        if ($text[0] !== '{') {
            if (preg_match('/\{(?:[^{}]|(?R))*\}/s', $text, $m)) {
                $text = $m[0];
            }
        }

        // Jetzt das eigentliche JSON der Felder dekodieren
        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            self::log('json_decode(content) failed: ' . (function_exists('json_last_error_msg') ? json_last_error_msg() : 'json error'));
            self::log('content head: ' . mb_substr($text, 0, 300));
            return null;
        }

        // Optionales Debug
        self::log('Decoded keys: ' . implode(',', array_keys($decoded)));

        return $decoded;
    }

    // interner Retry ohne response_format (falls nötig)
    private static function retryOpenAi(string $apiKey, array $payload, bool $debug): ?array
    {
        $ch = curl_init(self::OPENAI_API);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HEADER         => false,
        ]);
        $resp     = curl_exec($ch);
        $curlErr  = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false) {
            self::log('Retry cURL error: ' . $curlErr);
            return null;
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            self::log('Retry HTTP ' . $httpCode . ' body head: ' . mb_substr($resp, 0, 600));
            return null;
        }

        $data = json_decode($resp, true);
        $text = $data['choices'][0]['message']['content'] ?? '';
        if ($debug) {
            self::log('Retry body head: ' . mb_substr($text, 0, 600));
        }
        $text    = preg_replace('/^```json\s*|\s*```$/', '', trim($text));
        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            self::log('Retry decode failed; head: ' . mb_substr($text, 0, 400));
            return null;
        }
        return $decoded;
    }

    // Key-Resolver mit Fallbacks (ENV, $_ENV, $_SERVER, Secret-Datei, .env)
    private static function getApiKey(): ?string
    {
        $candidates = [
            getenv('OPENAI_API_KEY') ?: null,
            $_ENV['OPENAI_API_KEY'] ?? null,
            $_SERVER['OPENAI_API_KEY'] ?? null,
            function_exists('apache_getenv') ? apache_getenv('OPENAI_API_KEY', true) : null,
            is_readable('/run/secrets/openai_api_key') ? trim((string)file_get_contents('/run/secrets/openai_api_key')) : null,
            self::readEnvFile(__DIR__ . '/../workflow.env', 'OPENAI_API_KEY'),
        ];
        foreach ($candidates as $v) {
            if (is_string($v) && $v !== '') return trim($v);
        }
        return null;
    }

    private static function readEnvFile(string $path, string $key): ?string
    {
        if (!is_readable($path)) return null;
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = ltrim($line);
            if ($line === '' || $line[0] === '#') continue;
            if (strpos($line, '=') === false) continue;
            [$k, $val] = explode('=', $line, 2);
            if (trim($k) === $key) return trim($val, " \t\n\r\0\x0B\"'");
        }
        return null;
    }

    private static function normalize_bool($v): int
    {
        if (is_bool($v)) return $v ? 1 : 0;
        $s = strtolower((string)$v);
        return (in_array($s, ['1', 'true', 'yes', 'ja'], true)) ? 1 : 0;
    }

    private static function detect_direct_debit(string $content): int
    {
        $s = mb_strtolower($content, 'UTF-8');
        $hits = [
            'sepa-lastschrift',
            'sepa lastschrift',
            'lastschrift',
            'einzugsermächtigung',
            'sepa-mandat',
            'mandatsreferenz',
            'direct debit'
        ];
        foreach ($hits as $h) {
            if (mb_strpos($s, $h) !== false) return 1;
        }
        return 0;
    }

    private static function normalize_amount($val): ?string
    {
        if ($val === null || $val === '') return null;
        $s = trim((string)$val);
        // entferne Währung/Symbole/Spaces
        $s = str_replace(['€', 'EUR', 'eur', ' '], '', $s);
        // Komma zu Punkt, aber vorsichtig mit Tausenderpunkten:
        // Beispiel "1.234,56" -> "1234.56"
        $s = preg_replace('/\.(?=.*\.)/', '', $s); // alle Punkte außer evtl. letzter entfernen
        $s = str_replace(',', '.', $s);
        if (!is_numeric($s)) return null;
        return number_format((float)$s, 2, '.', '');
    }

    private static function normalize_date($v): ?string
    {
        if (!$v) return null;
        $v = trim((string)$v);
        // Versuche verschiedene deutsche Formate
        $candidates = [$v];
        // Ersetze z. B. 31.01.2024
        if (preg_match('/^\d{1,2}\.\d{1,2}\.\d{2,4}$/', $v)) {
            [$d, $m, $y] = preg_split('/\./', $v);
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

    private static function normalize_bic($bic): ?string
    {
        if (!$bic) return null;
        $bic = strtoupper(preg_replace('/\s+/', '', (string)$bic));
        if (preg_match('/^[A-Z0-9]{8}([A-Z0-9]{3})?$/', $bic)) {
            return $bic;
        }
        return null;
    }

    private static function normalize_iban($iban): ?string
    {
        if (!$iban) return null;
        $iban = strtoupper(preg_replace('/\s+/', '', (string)$iban));
        if (!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{10,30}$/', $iban)) {
            return null;
        }
        return self::ibanCheck($iban) ? $iban : null;
    }

    /** IBAN-Prüfung via Mod97 – pure PHP, ohne bcmath */
    private static function ibanCheck(string $iban): bool
    {
        // 1) Rearrange: erste 4 Zeichen ans Ende
        $rearranged = substr($iban, 4) . substr($iban, 0, 4);

        // 2) Buchstaben -> Zahlen (A=10 ... Z=35)
        $numeric = '';
        for ($i = 0, $l = strlen($rearranged); $i < $l; $i++) {
            $c = $rearranged[$i];
            if ($c >= 'A' && $c <= 'Z') {
                $numeric .= (string)(ord($c) - 55);
            } else {
                $numeric .= $c; // Ziffer
            }
        }

        // 3) Mod 97 iterativ über Ziffern
        $remainder = 0;
        for ($i = 0, $l = strlen($numeric); $i < $l; $i++) {
            $remainder = ($remainder * 10 + (int)$numeric[$i]) % 97;
        }
        return $remainder === 1;
    }

    private static function nn(?string $s): ?string
    {
        $s = trim((string)$s);
        return $s === '' ? null : $s;
    }

    // in App\Extract\Extractor
    private function call_openai_json_with_prompts(string $doctype, string $content): ?array
    {
        [$system, $user, $schemaWrap] = (new PromptLoader())->build($doctype, $content);

        $payload = [
            'model' => self::OPENAI_MODEL, // z.B. gpt-4o-mini
            'temperature' => 0.0,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $user],
            ],
            // JSON "hart" erzwingen (falls unterstützt)
            'response_format' => [
                // a) robust: json_schema (strikte Validierung)
                'type' => 'json_schema',
                'json_schema' => [
                    'name'   => $schemaWrap['name'] ?? 'extract',
                    'schema' => $schemaWrap['schema'] ?? ['type' => 'object']
                ]
                // b) falls dein OpenAI-GW json_schema noch nicht mag:
                // 'type' => 'json_object'
            ],
        ];

        $res = $this->http_post_openai($payload, true);
        $data = $this->oa_extract_json($res);
        Log::j('DEBUG', 'data', ['result' => $data]);
        return $data;
    }
    // 1) Response vereinheitlichen
    private function oa_payload(array $r): ?array
    {
        if (isset($r['json']) && is_array($r['json'])) return $r['json'];
        if (isset($r['body'])) {
            if (is_array($r['body'])) return $r['body'];
            if (is_string($r['body'])) {
                $j = json_decode($r['body'], true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
                if (is_array($j)) return $j;
            }
        }
        // evtl. kam schon direkt das Array zurück
        return $r ?: null;
    }

    // 1) Kompaktes Safe-JSON
function ai_json_decode(string $s): ?array {
    $s = trim($s);
    if (preg_match('/^```(?:json)?\s*(.*)```$/s', $s, $m)) $s = $m[1];
    $j = json_decode($s, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
    if (is_array($j)) return $j;
    $i = strpos($s, '{'); $jpos = strrpos($s, '}');
    if ($i !== false && $jpos !== false && $jpos > $i) {
        $cut = substr($s, $i, $jpos-$i+1);
        $j2 = json_decode($cut, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
        if (is_array($j2)) return $j2;
    }
    error_log('[AI] json_decode fail: '.json_last_error_msg().' head='.substr($s,0,120));
    return null;
}

// 2) Einheits-Zugriff auf "content"/"parsed" – egal wie der HTTP-Wrapper aussieht
function oa_extract_json($resp): ?array {
    // a) Falls schon ein fertiges Array der Felder übergeben wurde
    if (is_array($resp)) {
        // direktes Feld-Objekt? (issuer_name etc.)
        $keys = array_keys($resp);
        if (count(array_intersect($keys, [
            'issuer_name','invoice_date','invoice_number','invoice_amount',
            'issuer_iban','issuer_bic','payment_purpose','direct_debit'
        ])) >= 2) {
            return $resp;
        }
        // OpenAI-ähnliche Struktur?
        if (isset($resp['choices'][0]['message'])) {
            $msg = $resp['choices'][0]['message'];
            if (isset($msg['parsed']) && is_array($msg['parsed'])) return $msg['parsed'];
            if (isset($msg['content']) && is_string($msg['content'])) return $this->ai_json_decode($msg['content']);
            if (isset($msg['content']) && is_array($msg['content'])) {
                $buf = '';
                foreach ($msg['content'] as $part) {
                    if (($part['type'] ?? '') === 'output_text' || ($part['type'] ?? '') === 'text') {
                        $buf .= (string)($part['text'] ?? '');
                    }
                }
                if ($buf !== '') return $this->ai_json_decode($buf);
            }
            error_log('[AI] message present aber kein content/parsed');
            return null;
        }
        // Dein Wrapper-Shape: ['status'=>.., 'body'=> string|array ]
        if (isset($resp['body'])) {
            if (is_string($resp['body'])) {
                $j = json_decode($resp['body'], true);
                if (is_array($j)) return $this->oa_extract_json($j); // rekursiv
            } elseif (is_array($resp['body'])) {
                return $this->oa_extract_json($resp['body']);
            }
        }
        // Manche Logs: ['result'=> {...}]
        if (isset($resp['result']) && is_array($resp['result'])) return $resp['result'];
    }
    // b) Falls reiner String (direkt das JSON der Felder)
    if (is_string($resp)) return $this->ai_json_decode($resp);

    // c) Nichts Passendes gefunden → Struktur kurz loggen
    $t = is_array($resp) ? implode(',', array_keys($resp)) : gettype($resp);
    error_log('[AI] unknown response shape: '.$t);
    return null;
}

}

final class PromptLoader
{
    public function __construct(
        private string $promptsDir = '',
        private array $schemaCfg = []
    ) {
        $this->promptsDir = $promptsDir ?: (getenv('PROMPTS_DIR') ?: __DIR__ . '/../../prompts');
        $schemaFile = __DIR__ . '/../../config/extract-schema.php';
        $this->schemaCfg = is_file($schemaFile) ? (require $schemaFile) : [];
    }

    /** Liefert [system, user, schemaArray] für den gegebenen Doctype. */
    public function build(string $doctype, string $content): array
    {
        $system = $this->readFile('base.system.md') ?? 'Antworte ausschließlich als JSON.';
        $userTpl = $this->readFile(strtolower($doctype) . '.user.md')
            ?? $this->readFile('rechnung.user.md')    // Fallback
            ?? 'Dokumentinhalt:\n-----\n{{CONTENT}}\n-----\nAntworte als JSON.';

        // Schema bestimmen
        $schema = $this->schemaFor($doctype);

        // Platzhalter ersetzen
        $user = strtr($userTpl, [
            '{{CONTENT}}'    => $content,
            '{{SCHEMA_JSON}}' => json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        ]);

        return [$system, $user, $schema];
    }

    private function readFile(string $name): ?string
    {
        $path = rtrim($this->promptsDir, '/') . '/' . $name;
        return is_file($path) ? file_get_contents($path) : null;
    }

    private function schemaFor(string $doctype): array
    {
        $cfg = $this->schemaCfg;
        $entry = $cfg[$doctype] ?? $cfg['default'] ?? null;
        if ($entry === null) $entry = $cfg['default'] ?? [];
        // $entry kann direkt das ['name','schema']-Array sein
        if (is_array($entry) && isset($entry['schema'])) return $entry;
        // Fallback minimal
        return [
            'name' => 'extract',
            'schema' => ['type' => 'object', 'additionalProperties' => false, 'properties' => []]
        ];
    }
}
