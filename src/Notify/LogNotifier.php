<?php
namespace App\Notify;

use App\Log;

final class LogNotifier implements Notifier {
    public function send(string $to, string $subject, string $html, array $opts = []): bool {
        Log::j('INFO','notify.logonly', ['to'=>$to, 'subject'=>$subject]);
        return true;
    }
}
