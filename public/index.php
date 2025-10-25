<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Http\Json;
use App\Security\InternalAuth;
use App\Controller\ApiController;
use App\Http\HttpException;
use App\Config;

error_log('worker ROUTE: ' . ($_SERVER['REQUEST_METHOD'] ?? '?') . ' ' . ($_SERVER['REQUEST_URI'] ?? '?'));

$cfg = new \App\Config();  // <<< neu: zentral aus ENV

$paperless = new \App\Paperless\Client($cfg, $cfg->paperlessToken);
$doctypeSvc = new \App\Workflow\DoctypeService($paperless, $cfg->doctypesCacheFile, $cfg->doctypesTtl);

$api = new \App\Controller\ApiController($cfg, $paperless, $doctypeSvc);

$uri = $_SERVER['REQUEST_URI'] ?? '/';


// A) Healthcheck VOR allem
$uri  = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($uri, PHP_URL_PATH) ?: '/';
if ($path === '/ping' || $path === '/ping/') {
    header('Content-Type: text/plain; charset=utf-8');
    echo "pong\n";
    exit;
}
error_log('worker internal token: autoload ok' . $cfg->workerInternalToken);
// B) Debug-Header, damit wir IMMER JSON bekommen
header('Content-Type: application/json; charset=utf-8');

// C) sehr frühe Logs
error_log('worker BOOT: enter index.php, URI=' . $uri);

// D) Autoload (mit Vorprüfung; require-Fehler sind sonst nicht catchbar)
$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(500);
    echo json_encode(['error' => 'Missing autoload', 'detail' => $autoload . ' not found']);
    exit;
}
require $autoload;
error_log('worker BOOT: autoload ok');

try {
    // E) Config anlegen (lädt ENV über App\Env)
    $cfg = new \App\Config();
    error_log('worker BOOT: config ok url=' . $cfg->paperlessUrl);

    // F) Debug-Env-Endpoint
    if ($path === '/debug/env') {
        echo json_encode([
            'paperlessUrl'      => $cfg->paperlessUrl,
            'hasPaperlessToken' => $cfg->paperlessToken !== '',
            'hasWorkerToken'    => $cfg->workerInternalToken !== '',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // G) Methode + Pfad robust bestimmen
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path   = '/' . trim(parse_url($uri, PHP_URL_PATH) ?? '/', '/');
    if ($path === '') $path = '/';
    error_log("worker ROUTE: $method $path");

    // H) Interne Auth
    \App\Security\InternalAuth::assert($cfg->workerInternalToken);

    // I) Controller
    $api = new \App\Controller\ApiController($cfg, $paperless, $doctypeSvc);

    // GET abstrakte Liste für Dropdown
    if ($method === 'GET' && $path === '/api/v1/meta/doctypes') {
        echo json_encode($api->metaDoctypes(), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'PATCH' && preg_match('#^/api/v1/workflow/(\d+)/doctype$#', $path, $m)) {
        $body = readBody();
        error_log("PATCH doctype: doc={$m[1]} body=" . json_encode($body));
        echo json_encode($api->setDoctype((int)$m[1], $body), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'POST' && preg_match('#^/api/v1/workflow/(\d+)/commit/?$#', $path, $m)) {
        $raw  = file_get_contents('php://input') ?: '';
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            parse_str($raw, $tmp);
            $body = is_array($tmp) ? $tmp : [];
        }
        echo json_encode($api->commit((int)$m[1], $body), JSON_UNESCAPED_UNICODE);
        exit;
    }

    // PATCH /api/v1/workflow/{id}/title
    if ($method === 'PATCH' && preg_match('#^/api/v1/workflow/(\d+)/title$#', $path, $m)) {
        $id   = (int)$m[1];
        $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
        $title = trim((string)($body['title'] ?? ''));
        if ($title === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'title required']);
            exit;
        }
        $ok = $api->setTitle($id, $title);
        echo json_encode(['ok' => (bool)$ok], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // M) Fallback
    http_response_code(404);
    echo json_encode(['error' => "Endpoint not found: $method $path"], JSON_UNESCAPED_UNICODE);
} catch (\App\Http\HttpException $e) {
    http_response_code($e->status);
    echo json_encode(['error' => $e->getMessage(), 'code' => $e->status], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    // letzter Rettungsanker: IMMER JSON + Detail
    http_response_code(500);
    echo json_encode(['error' => 'Internal Server Error', 'detail' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}


function readBody(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $ct  = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ct, 'application/json') !== false) {
        $j = json_decode($raw, true);
        if (is_array($j)) return $j;
    }
    parse_str($raw, $out);
    return is_array($out) ? $out : [];
}
