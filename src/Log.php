<?php
namespace App;
final class Log {
  public static string $level = 'INFO'; // via ENV überschreibbar
  private static array $lvl = ['TRACE'=>10,'DEBUG'=>20,'INFO'=>30,'WARN'=>40,'ERROR'=>50];

  public static function init(): void {
    $env = getenv('LOG_LEVEL') ?: '';
    if ($env) self::$level = strtoupper($env);
  }

  public static function j(string $level, string $msg, array $ctx=[]): void {
    if (self::$lvl[$level] < (self::$lvl[self::$level] ?? 30)) return;
    $row = [
      'ts'   => date('c'),
      'lvl'  => $level,
      'msg'  => $msg,
      'ctx'  => $ctx,
      'comp' => 'wf',               // Komponente: wf/webhook/extract/…
      'host' => gethostname(),
    ];
    file_put_contents('php://stderr', json_encode($row, JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND);
  }
}
