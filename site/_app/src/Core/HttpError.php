<?php
declare(strict_types=1);

namespace Dnl\Core;

defined('DNL_APP') || exit;

/** Kullanıcıya güvenle gösterilebilecek mesajla birlikte HTTP hatası. */
final class HttpError extends \RuntimeException
{
    /** @param array<string,string> $fields alan bazlı doğrulama hataları */
    public function __construct(public int $status, string $message, public string $errorCode = 'error', public array $fields = [])
    {
        parent::__construct($message, $status);
    }
}
