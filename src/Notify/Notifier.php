<?php
namespace App\Notify;

interface Notifier {
    public function send(string $to, string $subject, string $html, array $opts = []): bool;
}
