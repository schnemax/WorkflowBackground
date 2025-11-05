<?php

namespace App\Paperless;

use App\Config;
use App\Http\HttpException;
use App\Paperless\Client as PaperlessClient;
use App\Log;
use App\Http\Json;
use App\Workflow\StateTags;


final class Client
{
    private string $baseUrl;
    private string $token;
    private int $timeout;
    private string $workflowUrl;

    public function __construct(Config $cfg)
    {
        $this->baseUrl = rtrim($cfg->paperlessUrl, '/');
        $this->token   = $cfg->paperlessToken;
        $this->timeout = 30;
        $this->workflowUrl = $cfg->workflowUrl;
    }

    /**
     * Low-level JSON request helper.
     * $path: absolute API path, e.g. "/api/documents/"
     * $query: array -> added to URL as query string
     * $body: array -> JSON encoded for POST/PATCH
     */
    public function requestJson(string $method, string $path, array $query = [], ?array $body = null): ?array
    {
        $url = $this->baseUrl . $path;
        //Log::j('INFO', 'requestJson', ['url' => $url]);
        if ($query) {
            // http_build_query nutzt RFC1738 (space=+); Paperless kommt damit klar
            $qs = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
            $url .= (str_contains($url, '?') ? '&' : '?') . $qs;
        }

        // cURL, wenn verfügbar
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $headers = [
                'Authorization: Token ' . $this->token,
                'Accept: application/json; version=9',
            ];
            if ($body !== null) {
                $headers[] = 'Content-Type: application/json';
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => $this->timeout,
            ]);
            $resp = curl_exec($ch);
            if ($resp === false) {
                curl_close($ch);
                return null;
            }
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code < 200 || $code >= 300) {
                return null;
            }
            $json = json_decode($resp, true);
            return is_array($json) ? $json : null;
        }

        // Fallback: stream_context
        $opts = [
            'http' => [
                'method'  => $method,
                'header'  => implode("\r\n", [
                    'Authorization: Token ' . $this->token,
                    'Accept: application/json; version=9',
                    ($body !== null ? 'Content-Type: application/json' : ''),
                ]),
                'content' => $body !== null ? json_encode($body, JSON_UNESCAPED_UNICODE) : '',
                'timeout' => $this->timeout,
            ],
        ];
        $ctx  = stream_context_create($opts);
        $resp = @file_get_contents($url, false, $ctx);
        if ($resp === false) return null;
        $json = json_decode($resp, true);
        return is_array($json) ? $json : null;
    }

    /**
     * Hole Dokumente mit beliebigen Filtern (paginiert wie Paperless-API).
     * Beispiel: getDocuments(['tags__id__all' => 123, 'ordering' => 'id', 'page' => 1, 'page_size' => 100])
     */
    public function getDocuments(array $query = []): ?array
    {
        // Defaults für Pagination
        $query += ['page' => 1, 'page_size' => 100];
        // Optional hilfreich: expand liefert Namen mit (abhängig von deiner Version)
        // wenn du sie brauchst, kannst du z. B. 'expand' => 'tags,document_type' mitgeben
        return $this->requestJson('GET', '/api/documents/', $query);
    }

    /**
     * Bequemer Iterator über alle Seiten. Ruft $yield($doc) für jedes Dokument auf.
     */
    public function iterDocuments(array $query, callable $yield, int $maxPages = 100): void
    {
        $page = (int)($query['page'] ?? 1);
        $query['page_size'] = (int)($query['page_size'] ?? 100);

        for ($i = 0; $i < $maxPages; $i++, $page++) {
            $query['page'] = $page;
            $res = $this->getDocuments($query);
            if (!$res || empty($res['results'])) break;

            foreach ($res['results'] as $doc) {
                $yield($doc);
            }
            if (empty($res['next'])) break; // keine nächste Seite
        }
    }

    // (Falls du eine ältere listDocuments(..) verwendest, kannst du die auf getDocuments abbilden:)

    /** @return array{status:int,location:?string,body:?array} */
    // general purpose request funtion to interact with paperless

    public function request(string $method, string $path, ?array $json = null): array
    {
        //$url  = $this->cfg->paperlessUrl . $path;
        $url = $this->baseUrl . $path;
        $headers = [
            'Authorization: Token ' . $this->token,
            'Accept: application/json; version=9',
        ];
        if ($json !== null) $hdrs[] = 'Content-Type: application/json';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($json !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json, JSON_UNESCAPED_UNICODE));
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return ['status' => 0, 'location' => null, 'body' => ['error' => 'curl', 'msg' => $err]];
        }
        $status  = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $hdrSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $hdrStr  = substr($raw, 0, $hdrSize);
        $bodyStr = substr($raw, $hdrSize);
        curl_close($ch);

        $loc = null;
        foreach (explode("\r\n", $hdrStr) as $line) {
            if (stripos($line, 'Location:') === 0) {
                $loc = trim(substr($line, 9));
                break;
            }
        }

        // Fallback bei 406: JSON erzwingen
        if ($status === 406 && !str_contains($path, 'format=json')) {
            return $this->request($method, $path . (str_contains($path, '?') ? '&' : '?') . 'format=json', $json);
        }

        $jsonBody = json_decode($bodyStr, true);
        return ['status' => $status, 'location' => $loc, 'body' => is_array($jsonBody) ? $jsonBody : null];
    }


    public function listDocuments(int $page = 1, int $pageSize = 100, array $query = []): ?array
    {
        $query['page'] = $page;
        $query['page_size'] = $pageSize;
        return $this->getDocuments($query);
    }

    public function patchDocumentType(int $docId, int $typeId): array
    {
        $payload = ['document_type' => $typeId];
        //Log::j('DEBUG', 'patchDocumentType', ['payload is' => $payload]);
        //return $this->request('PATCH', "/api/documents/{$docId}/", $payload);
        $code = 0;
        $body = '';
        $newTitle = null;
        $finalTagIds = [];
        $cfPatches = [];
        Log::j('DEBUG', 'patch_doctype', ['doctype' => $typeId]);
        $ok = $this->patchDocumentAtomic(
            $docId,
            $newTitle,        // null, wenn du den Titel nicht ändern willst
            $finalTagIds,      // null, wenn Tags unverändert bleiben sollen
            $cfPatches,        // null, wenn keine CF-Updates anstehen
            $code,
            $body,
            $doctype = $typeId
        );
        if ($ok) {
            return ['status' => '200'];
        } else {
            return ['status' => '500'];
        };
    }

    // vorhandene Methoden wie getDocument(..), patchDocument(..) bleiben unverändert

    public function bulkEdit(array $docIds, string $method, array $params): void
    {
        $this->request('POST', '/api/documents/bulk_edit/', [
            'documents' => $docIds,
            'method' => $method,
            'parameters' => $params
        ]);
    }

    public function ensureTag(string $name): ?int
    {
        $q = $this->request('GET', '/api/tags/?page_size=1&search=' . urlencode($name));
        $id = $q['body']['results'][0]['id'] ?? null;
        if ($id) return (int)$id;
        $c = $this->request('POST', '/api/tags/', ['name' => $name]);
        return $c['body']['id'] ?? null;
    }

    public function getTag(int $id): array
    {
        $r = $this->request('GET', "/api/tags/{$id}/");
        return $r['body'] ?? [];
    }

    // App/Paperless/Client.php

    public function documentUrl(int $id): string
    {
        // Nutzt deine Basis-URL aus Config (extern erreichbar!)
        //return rtrim($this->baseUrl, '/') . "/documents/{$id}/"; --> früher direkt nach Paperless

        return rtrim($this->workflowUrl, '/') . "/{$id}"; // --> jetzt zur Workflow Application
    }

    public function documentApiUrl(int $id): string
    {
        return rtrim($this->baseUrl, '/') . "/api/documents/{$id}/";
    }

    // Tags auflisten (paginiert)
    public function listTags(array $query = []): ?array
    {
        $query += ['page' => 1, 'page_size' => 100];
        return $this->requestJson('GET', '/api/tags/', $query);
    }

    // Tag per Name suchen (case-insensitive). Liefert die ID oder null.
    public function findTagIdByName(string $name): ?int
    {
        $name = trim($name);
        if ($name === '') return null;

        // Versuch 1: Serverseitig filtern (falls unterstützt)
        $res = $this->requestJson('GET', '/api/tags/', [
            'name__iexact' => $name,
            'page_size'    => 2,
        ]);
        //Log::j('DEBUG', 'findTagIdByName', ['res' => $res, 'name' => $name]);
        $items = $res['results'] ?? (is_array($res) ? $res : []);
        if (!empty($items)) {
            return isset($items[0]['id']) ? (int)$items[0]['id'] : null;
        }

        // Fallback: alle Seiten scannen
        $page = 1;
        do {
            $res = $this->listTags(['page' => $page, 'page_size' => 100]);
            if (!$res) break;
            $items = $res['results'] ?? [];
            foreach ($items as $t) {
                if (isset($t['name']) && mb_strtolower(trim((string)$t['name']), 'UTF-8') === mb_strtolower($name, 'UTF-8')) {
                    return (int)$t['id'];
                }
            }
            $page++;
        } while (!empty($res['next']));

        return null;
    }

    // Tag anlegen. Gibt die vollständige Tag-Response zurück (inkl. id) oder null.
    public function createTag(string $name, ?string $color = null): ?array
    {
        $payload = ['name' => $name];
        if ($color) {
            $payload['color'] = $color;
        } // optional; Paperless vergibt sonst Standardfarbe
        return $this->requestJson('POST', '/api/tags/', [], $payload);
    }

    public function tagsHistogram(array $names): array
    {
        $res = [];
        foreach ($names as $n) {
            $id = $this->findTagIdByName($n);
            if (!$id) continue;
            $docs = $this->getDocuments(['tags__id__all' => $id, 'page_size' => 1]); // nur count
            $res[$n] = $docs['count'] ?? null;
        }
        return $res;
    }
    /**
     * Tag-IDs eines Dokuments robust holen.
     * Versucht:
     *  - doc['tags'] (IDs)
     *  - doc['tags_data'][]['id'] (bei expand=tags)
     */
    public function getDocumentTagIds(int $id): array
    {
        $doc = $this->getDocument($id, ['expand' => 'tags']);
        $ids = [];

        if (is_array($doc)) {
            if (!empty($doc['tags']) && is_array($doc['tags'])) {
                $ids = array_map('intval', $doc['tags']);
            } elseif (!empty($doc['tags_data']) && is_array($doc['tags_data'])) {
                foreach ($doc['tags_data'] as $t) {
                    if (isset($t['id'])) $ids[] = (int)$t['id'];
                }
            }
        }
        return array_values(array_unique(array_filter($ids, fn($i) => $i > 0)));
    }
    // Ein Dokument laden – optional mit Query (z. B. expand=tags,document_type)
    public function getDocument(int $id, array $query = []): ?array
    {
        return $this->requestJson('GET', "/api/documents/{$id}/", $query);
    }

    // In App\Paperless\Client

    private array $tagCacheIdToName = [];
    private array $tagCacheNameToId = [];

    /** Cache einmal befüllen/auffrischen */
    private function refreshTagsCache(): void
    {
        // alle Tags paginiert laden
        $page = 1;
        $id2n = [];
        $n2id = [];
        do {
            $res = $this->requestJson('GET', '/api/tags/', ['page' => $page, 'page_size' => 200]);
            if (!$res) break;
            $items = $res['results'] ?? (is_array($res) ? $res : []);
            foreach ($items as $t) {
                $id   = (int)($t['id']   ?? 0);
                $name = trim((string)($t['name'] ?? ''));
                if ($id > 0 && $name !== '') {
                    $id2n[$id] = $name;
                    $n2id[mb_strtolower($name, 'UTF-8')] = $id;
                }
            }
            $page++;
        } while (!empty($res['next']));

        if ($id2n) {
            $this->tagCacheIdToName = $id2n;
            $this->tagCacheNameToId = $n2id;
        }
    }

    /** ID -> Name (per Cache, bei Miss wird Cache geladen) */
    public function getTagNameById(int $id): ?string
    {
        if (isset($this->tagCacheIdToName[$id])) return $this->tagCacheIdToName[$id];
        $this->refreshTagsCache();
        return $this->tagCacheIdToName[$id] ?? null;
    }

    /** Name -> ID (case-insensitive) */
    public function getTagIdByName(string $name): ?int
    {
        $key = mb_strtolower(trim($name), 'UTF-8');
        if ($key === '') return null;
        if (isset($this->tagCacheNameToId[$key])) return $this->tagCacheNameToId[$key];
        $this->refreshTagsCache();
        return $this->tagCacheNameToId[$key] ?? null;
    }

    /** Tags des Dokuments als NAMEN (bevorzugt via expand=tags, sonst map IDs → Namen) */
    public function getDocumentTagNames(int $docId): array
    {
        $doc = $this->getDocument($docId, ['expand' => 'tags']);
        $names = [];

        if (is_array($doc)) {
            if (!empty($doc['tags_data']) && is_array($doc['tags_data'])) {
                foreach ($doc['tags_data'] as $t) {
                    $n = trim((string)($t['name'] ?? ''));
                    if ($n !== '') $names[] = $n;
                }
            } elseif (!empty($doc['tags']) && is_array($doc['tags'])) {
                foreach ($doc['tags'] as $id) {
                    $n = $this->getTagNameById((int)$id);
                    if ($n) $names[] = $n;
                }
            }
        }
        // eindeutig
        $names = array_values(array_unique($names));
        return $names;
    }

    // in App\Paperless\Client::listCustomFields()
    public function listCustomFields(): array
    {
        $out = [];
        $page = 1;
        do {
            $res = $this->requestJson('GET', '/api/custom_fields/', ['page' => $page, 'page_size' => 200]);
            if (!$res) break;
            $items = $res['results'] ?? (is_array($res) ? $res : []);
            foreach ($items as $f) {
                $choices = $f['choices'] ?? [];
                $byLabel = [];
                foreach ($choices as $ch) {
                    $label = (string)($ch['label'] ?? $ch['name'] ?? '');
                    $id    = (string)($ch['id'] ?? '');
                    if ($label !== '' && $id !== '') {
                        $byLabel[mb_strtolower($label, 'UTF-8')] = $id;
                    }
                }
                $out[] = [
                    'id'                => $f['id'] ?? null,
                    'name'              => $f['name'] ?? '',
                    'data_type'         => $f['data_type'] ?? ($f['type'] ?? 'text'),
                    'choices'           => $choices,
                    'choices_by_label'  => $byLabel,   // <-- wichtig
                ];
            }
            $page++;
        } while (!empty($res['next']));
        return $out;
    }


    // App/Paperless/Client.php

    private array $cfCacheById = []; // [fieldId => def inkl. choices]

    /** Holt eine Custom-Field-Definition (inkl. choices), cached das Ergebnis. */
    public function getCustomFieldDef(int $id): ?array
    {
        if (isset($this->cfCacheById[$id])) return $this->cfCacheById[$id];

        // Versuch A: direkt /api/custom_fields/{id}/ (liefert oft already choices)
        $def = $this->requestJson('GET', "/api/custom_fields/{$id}/", []);
        if (is_array($def) && !empty($def)) {
            // Normalisiere choices -> by_label map
            $def['choices_by_label'] = [];
            foreach (($def['choices'] ?? []) as $ch) {
                $label = (string)($ch['label'] ?? $ch['name'] ?? '');
                $cid   = (string)($ch['id'] ?? '');
                if ($label !== '' && $cid !== '') {
                    $def['choices_by_label'][mb_strtolower($label, 'UTF-8')] = $cid;
                }
            }
            return $this->cfCacheById[$id] = $def;
        }

        // Versuch B: falls /{id}/ keine choices liefert, nochmal mit expand=choices
        $def = $this->requestJson('GET', "/api/custom_fields/{$id}/", ['expand' => 'choices']);
        if (is_array($def) && !empty($def)) {
            $def['choices_by_label'] = [];
            foreach (($def['choices'] ?? []) as $ch) {
                $label = (string)($ch['label'] ?? $ch['name'] ?? '');
                $cid   = (string)($ch['id'] ?? '');
                if ($label !== '' && $cid !== '') {
                    $def['choices_by_label'][mb_strtolower($label, 'UTF-8')] = $cid;
                }
            }
            return $this->cfCacheById[$id] = $def;
        }

        // Optionaler Versuch C: ältere Builds haben einen separaten Options-Endpunkt
        $base = $this->requestJson('GET', "/api/custom_fields/{$id}/", []);
        $opts = $this->requestJson('GET', '/api/custom_field_options/', ['field' => $id, 'page_size' => 200]);
        if (is_array($base)) {
            $base['choices'] = $opts['results'] ?? [];
            $base['choices_by_label'] = [];
            foreach (($base['choices'] ?? []) as $ch) {
                $label = (string)($ch['label'] ?? $ch['name'] ?? '');
                $cid   = (string)($ch['id'] ?? '');
                if ($label !== '' && $cid !== '') {
                    $base['choices_by_label'][mb_strtolower($label, 'UTF-8')] = $cid;
                }
            }
            return $this->cfCacheById[$id] = $base;
        }

        return null;
    }

    // Komfort: explizit inkl. Tags / Typ / Custom Fields
    public function getDocumentExpanded(int $id): ?array
    {
        return $this->getDocument($id, ['expand' => 'tags,document_type,custom_fields']);
    }

    // Tag-Namen aus einem bereits expandierten Dokument ziehen
    public function extractTagNamesFromDoc(array $doc): array
    {
        $names = [];
        if (!empty($doc['tags_data']) && is_array($doc['tags_data'])) {
            foreach ($doc['tags_data'] as $t) {
                $n = trim((string)($t['name'] ?? ''));
                if ($n !== '') $names[] = $n;
            }
        } elseif (!empty($doc['tags']) && is_array($doc['tags'])) {
            // Fallback: nur IDs vorhanden → per Cache ID→Name auflösen
            foreach ($doc['tags'] as $id) {
                $n = $this->getTagNameById((int)$id); // siehe frühere Helfer
                if ($n) $names[] = $n;
            }
        }
        return array_values(array_unique($names));
    }

    public function current_state_key(int $docId, array $doc, array $S, array $T, PaperlessClient $pl): ?string
    {
        // 1) Namen aus expand=tags (falls vorhanden)
        if (!empty($doc['tags_data'])) {
            foreach ($doc['tags_data'] as $t) {
                $key = StateTags::nametokey((string)($t['name'] ?? ''), $S, $GLOBALS['ALIASES'] ?? []);
                if ($key) return $key;
            }
        }

        // 2) IDs direkt matchen (robust, nicht lokalisiert)
        $ids = array_map('intval', $doc['tags'] ?? []);
        if ($ids) {
            foreach (['CLOSE', 'ERROR', 'APP_OK', 'SEPA', 'APP_REQ', 'UNVOLL',  'PRUEFEN'] as $key) {
                if (in_array((int)($T[$key] ?? -1), $ids, true)) return $key;
            }
        }

        // 3) Fallback: frisch expandiert laden
        $doc2 = $pl->getDocument($docId, ['expand' => 'tags']);

        foreach (($doc2['tags_data'] ?? []) as $t) {
            $key = StateTags::nametokey((string)($t['name'] ?? '')); 
            if ($key) return $key;
        }
        return null;
    }


    public function stateFromTagsSmart(
        int $docId,
        array $doc,
        array $S,             // Key => offizieller Tag-Name (Anzeige)
        array $T,             // Key => Tag-ID
        \App\Paperless\Client $pl,
        array $ALIASES = []
    ): ?string {
        // 1) Namen sammeln
        $names = [];
        if (!empty($doc['tags_data']) && is_array($doc['tags_data'])) {
            foreach ($doc['tags_data'] as $t) {
                $n = trim((string)($t['name'] ?? ''));
                if ($n !== '') $names[] = $n;
            }
        } else {
            // expand fallback
            $doc2 = $pl->getDocument($docId, ['expand' => 'tags']);
            foreach (($doc2['tags_data'] ?? []) as $t) {
                $n = trim((string)($t['name'] ?? ''));
                if ($n !== '') $names[] = $n;
            }
        }

        // 2) IDs sammeln
        $ids = array_map('intval', $doc['tags'] ?? []);
        if (!$ids) {
            $doc3 = $pl->getDocument($docId, []); // Detail ohne expand liefert i.d.R. ids
            $ids  = array_map('intval', $doc3['tags'] ?? []);
        }

        \App\Log::j('DEBUG', 'stateFromTagsSmart', ['doc' => $docId, 'names' => $names, 'ids' => $ids]);

        // 3) Priorität (finale zuerst)
        $prio = ['CLOSE', 'ERROR', 'APP_OK', 'SEPA', 'APP_REQ', 'UNVOLL', 'PRUEFEN'];

        // 3a) per Name → Key
        if ($names) {
            $namesN = array_map(fn($x) => $this->normTag($x), $names);
            foreach ($prio as $k) {
                $need = $S[$k] ?? null;
                if ($need && in_array($this->normTag((string)$need), $namesN, true)) return $k;
                // Aliase
                foreach ($namesN as $nn) {
                    if (($ALIASES[$nn] ?? null) === $k) return $k;
                }
            }
        }

        // 3b) per ID → Key (sprachunabhängig)
        if ($ids) {
            // baue ID→Key Map einmal
            $id2key = [];
            foreach ($T as $key => $id) {
                $id2key[(int)$id] = $key;
            }
            foreach ($prio as $k) {
                $want = (int)($T[$k] ?? 0);
                if ($want && in_array($want, $ids, true)) return $k;
            }
            // generischer Fallback: irgendein ID-Treffer
            foreach ($ids as $id) {
                if (isset($id2key[$id])) return $id2key[$id];
            }
        }

        return null;
    }
    public function normTag(string $s): string
    {
        $s = trim($s);
        $s = strtr($s, ['Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $s = str_replace(['_', '  '], [' ', ' '], $s);
        $s = preg_replace('~\s+~', ' ', $s);
        return mb_strtolower($s, 'UTF-8');
    }

    /** Mappt einen Tag-Anzeigenamen auf deinen State-Key (z. B. "APP_OK"), unter Nutzung von $S (offizielle Namen) + $ALIASES (Synonyme). */
    public function no_longer_used_nameToKey(string $tagName, array $S, array $ALIASES = []): ?string
    {
        $n = self::normTag($tagName);

        // 1) Direkter Vergleich gegen $S
        foreach ($S as $key => $displayName) {
            if ($this->normTag((string)$displayName) === $n) return $key;
        }
        // 2) Alias-Tabelle
        return $ALIASES[$n] ?? null;
    }


    public function patchDocumentDebug(int $id, array $payload, ?int &$code = null, ?string &$body = null): bool
    {
        $r = $this->request('PATCH', "/api/documents/{$id}/", $payload, $json = true, $wantBody = true);
        $code = $r['code'] ?? 0;
        $body = $r['body'] ?? '';
        return $code >= 200 && $code < 300;
    }

    public function patchDocumentAtomic(
        int $docId,
        ?string $title,
        ?array $tagIds,
        ?array $customFields, // Liste von ['field'=>int, 'value'=>scalar]
        ?int &$code = null,
        ?string &$body = null,
        ?int $doctype = null
    ): bool {
        $payload = [];
        if ($title !== null)  $payload['title'] = $title;
        if ($tagIds !== null) $payload['tags'] = array_values(array_unique(array_map('intval', $tagIds)));
        if ($customFields !== null) {
            // sicherstellen: Liste von Objekten, nicht Dict
            $payload['custom_fields'] = array_values(array_map(function ($x) {
                return ['field' => (int)$x['field'], 'value' => $x['value'] ?? null];
            }, $customFields));
        }
        if ($doctype !== null) $payload['document_type'] = $doctype;
        $url = rtrim($this->baseUrl, '/') . "/api/documents/{$docId}/"; // <-- trailing slash
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'PATCH',
            CURLOPT_HTTPHEADER     => [
                'Authorization: Token ' . $this->token,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_FOLLOWLOCATION => false, // wichtig: kein 301 verschlucken
            CURLOPT_TIMEOUT        => 30,
        ]);

        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        $body = is_string($resp) ? $resp : '';
        \App\Log::j(($code >= 200 && $code < 300) ? 'DEBUG' : 'ERROR', 'PATCH atomic', [
            'doc' => $docId,
            'code' => $code,
            'payload' => $payload,
            'resp' => $body,
            'err' => $err
        ]);

        return ($code >= 200 && $code < 300);
    }


    // --- ID → Name, mit Cache & Fallback-GET ---
    public function getDocumentTypeName(int $id): ?string
    {
        if ($id <= 0) return null;
        //if (isset($this->doctypeCache[$id])) return $this->doctypeCache[$id];

        // Falls Cache noch leer: einmal laden
        //if (!$this->doctypeCacheLoaded) $this->warmDocumentTypeCache();

        //if (isset($this->doctypeCache[$id])) return $this->doctypeCache[$id];

        // Immer noch nicht? Dann gezielt diesen Typ laden
        $row = $this->getDocumentType($id);
        if (!empty($row['name'])) {
            //return $this->doctypeCache[$id] = (string)$row['name'];
            return (string)$row['name'];
        }
        return null;
    }
    // --- kleine Response-Helper, passend zu deinem request() ---
    private function httpOk(array $r): bool
    {
        $code = $r['status'] ?? $r['code'] ?? 0;
        return ($code >= 200 && $code < 300);
    }
    private function payloadToArray(array $r): ?array
    {
        if (isset($r['json']) && is_array($r['json'])) return $r['json'];
        if (isset($r['body'])) {
            if (is_array($r['body'])) return $r['body'];
            if (is_string($r['body'])) {
                $j = json_decode($r['body'], true);
                if (json_last_error() === JSON_ERROR_NONE) return $j;
            }
        }
        return null;
    }

    // --- Einzelnen Dokumententyp holen ---
    public function getDocumentType(int $id): ?array
    {
        if ($id <= 0) return null;
        $r = $this->request('GET', "/api/document_types/{$id}/");
        if (!$this->httpOk($r)) return null;
        return $this->payloadToArray($r);
    }


    // src/Paperless/Client.php (Ausschnitt)
    public function listDocumentTypes(string $url = '/api/document_types/'): array
    {
        $resp = $this->request('GET', $url);     // mit Timeouts!
        return $resp['body'] ?? $resp;           // unwrap, falls du einen Wrapper nutzt
    }


    // ==================================================================================
    // all functions used by interface between wfyii and background service -> paperless
    // function duplicate_request muss noch genauer untersucht werden. Den request gegenüber
    // paperless gibt es ja schon -> prüfen, ob dieser in seiner bestehenden form allen
    // ansprüchen (background, endpoints) genügt
    // ==================================================================================
    /** PATCH /api/documents/{id}/  (document_type) */


    public function kickoutrequest(string $method, string $path, ?array $data = null, array $extraHeaders = []): array
    {
        $url = $this->baseUrl . $path;
        $ch = curl_init($url);
        $headers = array_merge([
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Token ' . $this->token,   // Paperless-Auth
        ], array_map(fn($k, $v) => $k . ': ' . $v, array_keys($extraHeaders), $extraHeaders));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_NOSIGNAL => true,
        ]);

        // Temporär zum Debuggen:
        error_log("paperless {$method} {$url}");

        if ($data !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($resp === false) throw new HttpException(502, curl_error($ch));
        curl_close($ch);

        $json = $resp !== '' ? json_decode($resp, true) : null;
        if ($code >= 400) throw new HttpException($code ?: 500, is_array($json) && isset($json['detail']) ? $json['detail'] : 'Paperless error');
        throw new HttpException($code, is_array($json));
        return is_array($json) ? $json : ['ok' => true];
    }

     /**
     * Liefert die ID eines Dokumenttyps anhand des Klartext-Namens.
     * - exakter Match (case-insensitive) über Feld "name"
     * - bei Mehrdeutigkeit Exception
     * - bei nicht gefunden: null
     */
    public function getDocumentTypeIdByName(string $docTypeName): ?int
    {
        $want = self::norm($docTypeName);
        $rows = $this->request('GET', '/api/document_types/');
        $matches = [];

        foreach ($rows as $row) {
            $name = isset($row['name']) ? self::norm((string)$row['name']) : '';
            if ($name === $want) {
                $matches[] = $row;
            }
        }

        if (count($matches) === 1) {
            return (int)$matches[0]['id'];
        }
 
        // Fallback: eindeutiger Teilstring?
        $subs = array_values(array_filter($rows, function ($row) use ($want) {
            $name = isset($row['name']) ? self::norm((string)$row['name']) : '';
            return $name !== '' && mb_strpos($name, $want) !== false;
        }));

        if (count($subs) === 1) {
            return (int)$subs[0]['id'];
        }


        return null; // nichts gefunden
    }

    /**
     * Liefert die ID eines Benutzerfelds (Custom Field) anhand des Namens.
     * - exakter Match über "name" ODER "slug" (case-insensitive)
     * - bei Mehrdeutigkeit Exception
     * - bei nicht gefunden: null
     */
    public function getCustomFieldIdByName(string $fieldName): ?int
    {
        $want = self::norm($fieldName);
        $rows = $this->request('GET', '/api/custom_fields/');
        $matches = [];

        foreach ($rows as $row) {
            $name = isset($row['name']) ? self::norm((string)$row['name']) : '';
            $slug = isset($row['slug']) ? self::norm((string)$row['slug']) : '';
            if ($name === $want || ($slug !== '' && $slug === $want)) {
                $matches[] = $row;
            }
        }

        if (count($matches) === 1) {
            return (int)$matches[0]['id'];
        }


        // Fallback: eindeutiger Teilstring (name oder slug)
        $subs = array_values(array_filter($rows, function ($row) use ($want) {
            $name = isset($row['name']) ? self::norm((string)$row['name']) : '';
            $slug = isset($row['slug']) ? self::norm((string)$row['slug']) : '';
            $hitName = ($name !== '' && mb_strpos($name, $want) !== false);
            $hitSlug = ($slug !== '' && mb_strpos($slug, $want) !== false);
            return $hitName || $hitSlug;
        }));

        if (count($subs) === 1) {
            return (int)$subs[0]['id'];
        }


        return null;
    }
 /** Normalisiert Namen für robusten Vergleich. */
    private static function norm(string $s): string
    {
        $s = trim($s);
        $s = mb_strtolower($s, 'UTF-8');
        // einfache Normalisierung (Leerzeichen vereinheitlichen)
        $s = preg_replace('/\s+/u', ' ', $s);
        return $s;
    }
}
