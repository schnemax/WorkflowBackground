<?php namespace App;

final class Http {
  static function jsonInput(): array {
    $raw = file_get_contents('php://input') ?: '';
    $in  = json_decode($raw, true);
    return is_array($in) ? $in : [];
  }
  static function ok(array $data=[]): never {
    header('Content-Type: application/json');
    echo json_encode(['ok'=>true]+$data, JSON_UNESCAPED_UNICODE);
    exit;
  }
  static function fail(string $msg, array $extra=[]): never {
    header('Content-Type: application/json');
    echo json_encode(['ok'=>false,'error'=>$msg]+$extra, JSON_UNESCAPED_UNICODE);
    exit;
  }
  static function requireSecret(string $expected): void {
    if ($expected==='') return;
    $got = $_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? '';
    if (!hash_equals($expected, $got)) self::fail('unauthorized');
  }
}
