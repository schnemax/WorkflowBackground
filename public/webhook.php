<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

use App\Config;
use App\Controller\WebhookController;

// ---- Content-Type einmalig setzen
header('Content-Type: application/json; charset=utf-8');

// ---- AUTH (X-Workflow-Token oder Bearer)
$all  = function_exists('getallheaders') ? getallheaders() : [];
$need = trim((string) getenv('WORKFLOW_SECRET'), " \t\r\n\"'");
$have = $all['X-Workflow-Token'] ?? ($_SERVER['HTTP_X_WORKFLOW_TOKEN'] ?? '');

if (!$have) {
    $auth = $all['Authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if ($auth && preg_match('/^\s*Bearer\s+(.+)\s*$/i', $auth, $m)) {
        $have = $m[1];
    }
}
if (!$have && isset($_GET['key'])) {
    $have = (string) $_GET['key'];
}
$have = trim($have);
if ($need === '' || !hash_equals($need, $have)) {
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'unauthorized']); exit;
}
error_log('AUTH need='.$need);
error_log('AUTH have='.$have);
// ... nach erfolgreicher Token-Prüfung:
define('WEBHOOK_AUTH_OK', true);

// ---- PAYLOAD EINMAL LESEN (JSON oder multipart)
$ct = $_SERVER['CONTENT_TYPE'] ?? '';
$payload = [];
if (is_string($ct) && stripos($ct, 'application/json') === 0) {
    $raw = file_get_contents('php://input') ?: '';
    $tmp = json_decode($raw, true);
    $payload = is_array($tmp) ? $tmp : [];
} elseif (is_string($ct) && stripos($ct, 'multipart/form-data') === 0) {
    $payload = $_POST;
}

// NICHT: echo/exit oder Body lesen
// Danach wie gehabt dein Controller-Routing aufrufen …


// ---- CONTROLLER CALL (nur noch hier antworten)
try {
    $cfg = new Config();
    $controller = new WebhookController($cfg);

    // Bevorzugt: Controller akzeptiert Payload als Argument
    //if (method_exists($controller, 'handleReextract')) {
    //    $controller->handleReextract($payload); // sollte selbst echo+exit machen
    //    exit;
    //}
    if ((new \ReflectionMethod($controller, 'handleWebhook'))->getNumberOfParameters() >= 1) {
        error_log('Call handleWebhook mit parameter');
        $controller->handleWebhook($payload);   // Controller antwortet
    } else {
        error_log('Call handleWebhook ohne parameter');
        $controller->handleWebhook();           // Legacy
    }
    exit;

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'internal error','msg'=>$e->getMessage()]);
    exit;
}
