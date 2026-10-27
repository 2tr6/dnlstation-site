<?php
declare(strict_types=1);

namespace Dnl\Core;

defined('DNL_APP') || exit;

/**
 * Kimlik doğrulamalı SMTP istemcisi (ek kütüphane yok).
 * secure: ssl (465, doğrudan TLS) | tls (587, STARTTLS) | none (yalnızca aynı sunucu: localhost)
 */
final class Smtp
{
    /** @var resource|null */
    private $s = null;
    public string $lastError = '';

    public function __construct(private array $c)
    {
    }

    private function fail(string $msg): bool
    {
        $this->lastError = $msg;
        if (is_resource($this->s)) {
            @fwrite($this->s, "QUIT\r\n");
            @fclose($this->s);
        }
        $this->s = null;
        return false;
    }

    private function read(): array
    {
        $data = '';
        while (is_resource($this->s) && ($line = fgets($this->s, 1024)) !== false) {
            $data .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        return [(int)substr($data, 0, 3), trim($data)];
    }

    private function cmd(string $line, array $ok, bool $secret = false): ?string
    {
        fwrite($this->s, $line . "\r\n");
        [$code, $text] = $this->read();
        if (!in_array($code, $ok, true)) {
            $shown = $secret ? '[gizli]' : (strlen($line) > 80 ? substr($line, 0, 20) . '…' : $line);
            $this->lastError = "$shown → $text";
            return null;
        }
        return $text;
    }

    public function send(string $from, string $to, string $data): bool
    {
        $host = (string)$this->c['host'];
        $port = (int)$this->c['port'];
        $secure = (string)($this->c['secure'] ?? 'ssl');
        $verify = $secure !== 'none' && ($this->c['verify'] ?? true);
        $ctx = stream_context_create(['ssl' => ['verify_peer' => $verify, 'verify_peer_name' => $verify, 'peer_name' => $host, 'SNI_enabled' => true]]);
        $errno = 0;
        $errstr = '';
        $s = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$s) {
            return $this->fail("Bağlantı kurulamadı ($host:$port): $errstr");
        }
        $this->s = $s;
        stream_set_timeout($s, 20);
        [$code, $text] = $this->read();
        if ($code !== 220) {
            return $this->fail('Sunucu karşılamadı: ' . $text);
        }
        $me = preg_replace('/[^a-z0-9.\-]/i', '', (string)(parse_url((string)Config::get('app_url'), PHP_URL_HOST) ?: 'localhost'));
        if ($this->cmd("EHLO $me", [250]) === null) {
            return $this->fail($this->lastError);
        }
        if ($secure === 'tls') {
            if ($this->cmd('STARTTLS', [220]) === null) {
                return $this->fail($this->lastError);
            }
            stream_context_set_option($s, 'ssl', 'peer_name', $host);
            if (!@stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                return $this->fail('TLS başlatılamadı');
            }
            if ($this->cmd("EHLO $me", [250]) === null) {
                return $this->fail($this->lastError);
            }
        }
        if (!empty($this->c['user'])) {
            if ($this->cmd('AUTH LOGIN', [334]) === null || $this->cmd(base64_encode((string)$this->c['user']), [334], true) === null || $this->cmd(base64_encode((string)$this->c['pass']), [235], true) === null) {
                return $this->fail('Kimlik doğrulama başarısız (kullanıcı adı ya da şifre hatalı olabilir)');
            }
        }
        if ($this->cmd("MAIL FROM:<$from>", [250]) === null || $this->cmd("RCPT TO:<$to>", [250, 251]) === null || $this->cmd('DATA', [354]) === null) {
            return $this->fail($this->lastError);
        }
        // Nokta ile başlayan satırlar kaçışlanır (RFC 5321)
        $data = preg_replace('/^\./m', '..', $data) ?? $data;
        if ($this->cmd($data . "\r\n.", [250]) === null) {
            return $this->fail($this->lastError);
        }
        @fwrite($s, "QUIT\r\n");
        @fclose($s);
        $this->s = null;
        return true;
    }
}
