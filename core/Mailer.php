<?php
declare(strict_types=1);

class Mailer
{
    public static function send(string $to, string $subject, string $text, ?string $html = null): bool
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $from = 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $from = preg_replace('/:\\d+$/', '', $from) ?? $from;

        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

        $headers = [
            'MIME-Version: 1.0',
            'From: ScriptCMS <' . $from . '>',
            'Reply-To: ' . $from,
        ];

        if ($html !== null) {
            $boundary = '=_ScriptCMS_' . bin2hex(random_bytes(12));
            $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

            $body = '--' . $boundary . "\r\n";
            $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
            $body .= $text . "\r\n\r\n";
            $body .= '--' . $boundary . "\r\n";
            $body .= "Content-Type: text/html; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
            $body .= $html . "\r\n\r\n";
            $body .= '--' . $boundary . "--\r\n";
        } else {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $body = $text;
        }

        $headerString = implode("\r\n", $headers);

        return @mail($to, $encodedSubject, $body, $headerString);
    }
}
