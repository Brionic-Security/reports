<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Email transport. Sends a multipart (text + HTML) message via one of three
 * drivers: log (dev), mail (PHP mail()), or smtp (dependency-free minimal SMTP
 * client with STARTTLS / implicit TLS and AUTH LOGIN).
 *
 * All sends are best-effort: a transport failure is logged, never thrown.
 */
final class Mailer
{
    public static function send(string $toEmail, string $subject, string $html, string $text = '', ?string $toName = null, ?string $replyTo = null): bool
    {
        $driver = (string) config('mail.driver', 'log');
        $fromEmail = (string) config('mail.from.address');
        $fromName = (string) config('mail.from.name');
        if ($text === '') {
            $text = trim(strip_tags(preg_replace('/<(br|\/p|\/div|\/h[1-6])>/i', "\n", $html) ?? $html));
        }

        try {
            return match ($driver) {
                'smtp'  => self::sendSmtp($toEmail, $toName, $subject, $html, $text, $fromEmail, $fromName, $replyTo),
                'mail'  => self::sendMail($toEmail, $subject, $html, $fromEmail, $fromName),
                default => self::sendLog($toEmail, $subject, $html, $text),
            };
        } catch (\Throwable $e) {
            logger('mail send failed: ' . $e->getMessage(), ['to' => self::maskEmail($toEmail), 'driver' => $driver]);
            return false;
        }
    }

    private static function sendLog(string $to, string $subject, string $html, string $text): bool
    {
        $entry = "==== " . gmdate('c') . " ====\n"
            . "To: " . self::maskEmail($to) . "\nSubject: {$subject}\n\n{$text}\n\n[HTML " . strlen($html) . " bytes]\n\n";
        @file_put_contents(storage_path('logs/mail.log'), $entry, FILE_APPEND);
        return true;
    }

    private static function maskEmail(string $email): string
    {
        $at = strpos($email, '@');
        if ($at === false || $at < 1) {
            return '***';
        }
        return $email[0] . str_repeat('*', max(1, $at - 1)) . substr($email, $at);
    }

    private static function sendMail(string $to, string $subject, string $html, string $fromEmail, string $fromName): bool
    {
        $boundary = 'b' . bin2hex(random_bytes(8));
        $headers = [
            'MIME-Version: 1.0',
            'From: ' . self::encodeName($fromName) . " <{$fromEmail}>",
            'Reply-To: ' . $fromEmail,
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];
        $text = trim(strip_tags($html));
        $body = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n{$text}\r\n\r\n"
            . "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n{$html}\r\n\r\n--{$boundary}--";
        return mail($to, self::encodeSubject($subject), $body, implode("\r\n", $headers));
    }

    private static function sendSmtp(string $to, ?string $toName, string $subject, string $html, string $text, string $fromEmail, string $fromName, ?string $replyTo = null): bool
    {
        $cfg = (array) config('mail.smtp');
        $host = (string) ($cfg['host'] ?? '');
        $port = (int) ($cfg['port'] ?? 587);
        $enc = (string) ($cfg['encryption'] ?? 'tls');
        $timeout = (int) ($cfg['timeout'] ?? 15);
        if ($host === '') {
            throw new \RuntimeException('SMTP host not configured.');
        }

        $transport = $enc === 'ssl' ? "ssl://{$host}" : $host;
        $fp = @stream_socket_client("{$transport}:{$port}", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]));
        if (!$fp) {
            throw new \RuntimeException("SMTP connect failed: {$errstr} ({$errno})");
        }
        stream_set_timeout($fp, $timeout);

        $read = function () use ($fp): string {
            $data = '';
            while (($line = fgets($fp, 515)) !== false) {
                $data .= $line;
                if (isset($line[3]) && $line[3] === ' ') {
                    break;
                }
            }
            return $data;
        };
        $cmd = function (string $c, array $ok) use ($fp, $read): string {
            fwrite($fp, $c . "\r\n");
            $resp = $read();
            $code = (int) substr($resp, 0, 3);
            if (!in_array($code, $ok, true)) {
                throw new \RuntimeException('SMTP error after "' . explode("\r\n", $c)[0] . '": ' . trim($resp));
            }
            return $resp;
        };

        $read(); // greeting
        $ehlo = 'EHLO ' . (gethostname() ?: 'localhost');
        $cmd($ehlo, [250]);

        if ($enc === 'tls') {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new \RuntimeException('STARTTLS negotiation failed.');
            }
            $cmd($ehlo, [250]);
        }

        $cmd('AUTH LOGIN', [334]);
        $cmd(base64_encode((string) ($cfg['username'] ?? '')), [334]);
        $cmd(base64_encode((string) ($cfg['password'] ?? '')), [235]);

        $cmd('MAIL FROM:<' . $fromEmail . '>', [250]);
        $cmd('RCPT TO:<' . $to . '>', [250, 251]);
        $cmd('DATA', [354]);

        $message = self::buildMime($to, $toName, $subject, $html, $text, $fromEmail, $fromName, $replyTo);
        $message = preg_replace('/^\./m', '..', $message);
        $cmd($message . "\r\n.", [250]);
        $cmd('QUIT', [221, 250]);
        fclose($fp);

        return true;
    }

    private static function buildMime(string $to, ?string $toName, string $subject, string $html, string $text, string $fromEmail, string $fromName, ?string $replyTo = null): string
    {
        $boundary = 'b' . bin2hex(random_bytes(10));
        $toHeader = $toName ? self::encodeName($toName) . " <{$to}>" : $to;
        $fromDomain = substr(strrchr($fromEmail, '@') ?: '@brionicsecurity.com', 1);
        $headers = [
            'Date: ' . gmdate('r'),
            'From: ' . self::encodeName($fromName) . " <{$fromEmail}>",
            'To: ' . $toHeader,
            'Reply-To: ' . (($replyTo !== null && $replyTo !== '') ? $replyTo : $fromEmail),
            'Subject: ' . self::encodeSubject($subject),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $fromDomain . '>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];
        if (str_contains(strtolower((string) config('mail.smtp.host', '')), 'sendgrid')) {
            $headers[] = 'X-SMTPAPI: {"filters":{"clicktrack":{"settings":{"enable":0}},"opentrack":{"settings":{"enable":0}}}}';
        }
        $body = "--{$boundary}\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n"
            . $text . "\r\n\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n"
            . $html . "\r\n\r\n"
            . "--{$boundary}--\r\n";
        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    private static function encodeName(string $name): string
    {
        return preg_match('/[^\x20-\x7e]/', $name)
            ? '=?UTF-8?B?' . base64_encode($name) . '?='
            : '"' . str_replace('"', '', $name) . '"';
    }

    private static function encodeSubject(string $subject): string
    {
        return preg_match('/[^\x20-\x7e]/', $subject)
            ? '=?UTF-8?B?' . base64_encode($subject) . '?='
            : $subject;
    }
}
