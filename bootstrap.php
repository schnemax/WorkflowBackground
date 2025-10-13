<?php
declare(strict_types=1);

// 1) Vendor-Autoloader (für PHPMailer etc.)
$vendor = __DIR__ . '/vendor/autoload.php';
if (is_file($vendor)) { require $vendor; }

// 2) Fallback-PSR-4 nur für App\* (falls Composer-Autoload nicht greift)
spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;

    $rel  = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = __DIR__ . '/src/' . $rel . '.php';

    // Diagnose: bei Bedarf die Auflösung loggen
    if (getenv('DEBUG_AUTOLOAD')) {
        error_log("[autoload] $class => $file " . (is_file($file) ? 'OK' : 'MISS'));
    }

    if (is_file($file)) { require $file; }
});

// 3) Optional: Logger initialisieren, wenn vorhanden
if (class_exists(\App\Log::class)) {
    \App\Log::init();
} else {
    error_log('[bootstrap] App\\Log not found; continuing without Log::init()');
}


