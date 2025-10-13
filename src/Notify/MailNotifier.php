<?php
namespace App\Notify;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use App\Log;

final class MailNotifier implements Notifier
{
    private string $host;
    private int    $port;
    private string $user;
    private string $pass;
    private string $secure; // tls|ssl|none|auto
    private string $from;
    private string $fromName;
    private ?string $replyTo;
    private bool $allowSelfSigned;

    public static function fromEnv(): self {
        return new self(
            getenv('SMTP_HOST') ?: 'localhost',
            (int)(getenv('SMTP_PORT') ?: 587),
            getenv('SMTP_USER') ?: '',
            getenv('SMTP_PASS') ?: '',
            strtolower(getenv('SMTP_SECURE') ?: 'tls'),
            getenv('SMTP_FROM') ?: 'no-reply@localhost',
            getenv('SMTP_FROM_NAME') ?: 'Workflow',
            getenv('SMTP_REPLY_TO') ?: null,
            (bool)(getenv('SMTP_ALLOW_SELF_SIGNED') ?: false),
        );
    }

    public function __construct(
        string $host, int $port, string $user, string $pass,
        string $secure, string $from, string $fromName,
        ?string $replyTo, bool $allowSelfSigned = false
    ) {
        $this->host = $host; $this->port = $port; $this->user = $user; $this->pass = $pass;
        $this->secure = $secure; $this->from = $from; $this->fromName = $fromName;
        $this->replyTo = $replyTo; $this->allowSelfSigned = $allowSelfSigned;
    }

    public function send(string $to, string $subject, string $html, array $opts = []): bool
    {
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = $this->host;
            $mail->Port = $this->port;
            $mail->SMTPAuth = ($this->user !== '' || $this->pass !== '');
            if ($this->secure === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; // 465
            } elseif ($this->secure === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // 587
            } elseif ($this->secure === 'none') {
                $mail->SMTPSecure = false;
                $mail->SMTPAutoTLS = false;
            } else { // auto
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            }
            if ($this->allowSelfSigned) {
                $mail->SMTPOptions = [
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true,
                    ]
                ];
            }
            if ($mail->SMTPAuth) {
                $mail->Username = $this->user;
                $mail->Password = $this->pass;
            }

            $mail->setFrom($this->from, $this->fromName);
            if ($this->replyTo) $mail->addReplyTo($this->replyTo);

            // Empfänger (unterstützt Arrays/Komma-getrennt)
            foreach ($this->normalizeRecipients($to) as $addr) $mail->addAddress($addr);
            foreach ($this->normalizeRecipients($opts['cc']  ?? '') as $addr) $mail->addCC($addr);
            foreach ($this->normalizeRecipients($opts['bcc'] ?? '') as $addr) $mail->addBCC($addr);

            // Anhänge
            foreach ((array)($opts['attachments'] ?? []) as $att) {
                if (is_array($att)) { $mail->addAttachment($att['path'] ?? '', $att['name'] ?? ''); }
                elseif (is_string($att)) { $mail->addAttachment($att); }
            }

            $mail->Subject = $subject;
            $mail->isHTML(true);
            $mail->Body    = $html;
            $mail->AltBody = $opts['text'] ?? self::htmlToText($html);

            $mail->send();
            Log::j('DEBUG','mail.sent', ['to'=>$to, 'subject'=>$subject]);
            return true;
        } catch (Exception $e) {
            Log::j('ERROR','mail.fail', ['to'=>$to, 'subject'=>$subject, 'error'=>$e->getMessage()]);
            return false;
        }
    }

    private function normalizeRecipients(string|array $r): array {
        if (is_array($r)) return array_values(array_filter(array_map('trim',$r)));
        if (strpos($r, ',') !== false) return array_values(array_filter(array_map('trim', explode(',', $r))));
        $r = trim($r); return $r ? [$r] : [];
    }

    private static function htmlToText(string $html): string {
        $t = preg_replace('#<br\s*/?>#i', "\n", $html);
        $t = preg_replace('#</p>#i', "\n\n", $t);
        $t = strip_tags($t ?? '');
        return trim($t ?? '');
    }
}
