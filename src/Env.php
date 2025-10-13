<?php

namespace App;

final class Env
{
    public static function loadFromFile(?string $file = null): void
    {
        $file = $file ?? getenv('WORKER_ENV_FILE') ?: '/var/nas002/paperless-ngx/app/workflow.env';
        if (!is_readable($file)) return;

        $env = parse_ini_file($file, false, INI_SCANNER_RAW) ?: [];
        foreach ($env as $k => $v) {
            // in getenv(), $_SERVER verfügbar machen
            putenv("$k=$v");
            $_SERVER[$k] = $v;
        }
    }
}
