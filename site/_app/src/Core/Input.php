<?php
declare(strict_types=1);

namespace Dnl\Core;

defined('DNL_APP') || exit;

/**
 * Girdi doğrulayıcı. Hatalar toplanır, check() ile tek seferde 422 döner.
 * Kullanım: $in = new Input($data); $name = $in->str('name', 2, 80); $in->check();
 */
final class Input
{
    /** @var array<string,string> */
    private array $errors = [];

    public function __construct(private array $data)
    {
    }

    public function has(string $k): bool
    {
        return array_key_exists($k, $this->data) && $this->data[$k] !== null && $this->data[$k] !== '';
    }

    public function str(string $k, int $min = 0, int $max = 255, bool $required = true, string $label = 'Bu alan'): string
    {
        $v = $this->data[$k] ?? '';
        if (!is_string($v) && !is_int($v)) {
            $this->errors[$k] = "$label geçersiz.";
            return '';
        }
        // Kontrol karakterlerini temizle, boşlukları sadeleştir
        $v = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)$v) ?? '');
        $v = preg_replace('/\s{2,}/u', ' ', $v) ?? $v;
        $len = mb_strlen($v);
        if ($v === '' && $required) {
            $this->errors[$k] = "$label zorunludur.";
        } elseif ($v !== '' && $len < $min) {
            $this->errors[$k] = "$label en az $min karakter olmalı.";
        } elseif ($len > $max) {
            $this->errors[$k] = "$label en fazla $max karakter olabilir.";
        }
        return $v;
    }

    /** Ham metin (şifre gibi): boşluk/temizlik yapılmaz */
    public function raw(string $k, int $min = 0, int $max = 255, string $label = 'Bu alan'): string
    {
        $v = $this->data[$k] ?? '';
        if (!is_string($v)) {
            $this->errors[$k] = "$label geçersiz.";
            return '';
        }
        $len = mb_strlen($v);
        if ($len < $min) {
            $this->errors[$k] = $v === '' ? "$label zorunludur." : "$label en az $min karakter olmalı.";
        } elseif (strlen($v) > $max) {
            $this->errors[$k] = "$label çok uzun.";
        }
        return $v;
    }

    public function email(string $k = 'email'): string
    {
        $v = mb_strtolower($this->str($k, 3, 190, true, 'E-posta'));
        if (!isset($this->errors[$k]) && filter_var($v, FILTER_VALIDATE_EMAIL) === false) {
            $this->errors[$k] = 'Geçerli bir e-posta adresi girin.';
        }
        return $v;
    }

    public function phone(string $k = 'phone', bool $required = true): string
    {
        $v = preg_replace('/\D+/', '', (string)($this->data[$k] ?? '')) ?? '';
        if ($v === '' && !$required) {
            return '';
        }
        if (str_starts_with($v, '90') && strlen($v) === 12) {
            $v = '0' . substr($v, 2);
        }
        if (strlen($v) === 10 && $v[0] === '5') {
            $v = '0' . $v;
        }
        if (!preg_match('/^0\d{10}$/', $v)) {
            $this->errors[$k] = 'Geçerli bir telefon numarası girin (05XX XXX XX XX).';
        }
        return $v;
    }

    public function int(string $k, int $min, int $max, ?int $default = null, string $label = 'Değer'): ?int
    {
        $v = $this->data[$k] ?? null;
        if ($v === null || $v === '') {
            if ($default === null) {
                $this->errors[$k] = "$label zorunludur.";
            }
            return $default;
        }
        if (is_string($v) && preg_match('/^-?\d+$/', $v)) {
            $v = (int)$v;
        }
        if (!is_int($v) || $v < $min || $v > $max) {
            $this->errors[$k] = "$label $min ile $max arasında olmalı.";
            return $default;
        }
        return $v;
    }

    /** Opsiyonel tam sayı: boşsa null */
    public function intOrNull(string $k, int $min, int $max, string $label = 'Değer'): ?int
    {
        if (!$this->has($k)) {
            return null;
        }
        return $this->int($k, $min, $max, null, $label);
    }

    /** TL tutarı (ör. 12,50 / "12.5" / 12) -> kuruş */
    public function money(string $k, bool $required = true, int $maxCents = 100000000, string $label = 'Tutar'): ?int
    {
        $v = $this->data[$k] ?? null;
        if ($v === null || $v === '') {
            if ($required) {
                $this->errors[$k] = "$label zorunludur.";
            }
            return null;
        }
        if (is_int($v) || is_float($v)) {
            $cents = (int)round(((float)$v) * 100);
        } elseif (is_string($v) && preg_match('/^\s*(\d{1,7})(?:[.,](\d{1,2}))?\s*$/', $v, $m)) {
            $cents = (int)$m[1] * 100 + (int)str_pad($m[2] ?? '0', 2, '0');
        } else {
            $this->errors[$k] = "$label geçersiz.";
            return null;
        }
        if ($cents < 0 || $cents > $maxCents) {
            $this->errors[$k] = "$label geçersiz.";
            return null;
        }
        return $cents;
    }

    /** @param string[] $allowed */
    public function enum(string $k, array $allowed, ?string $default = null, string $label = 'Seçim'): string
    {
        $v = $this->data[$k] ?? $default;
        if (!is_string($v) || !in_array($v, $allowed, true)) {
            $this->errors[$k] = "$label geçersiz.";
            return (string)($default ?? $allowed[0]);
        }
        return $v;
    }

    public function bool(string $k, bool $default = false): bool
    {
        $v = $this->data[$k] ?? $default;
        return $v === true || $v === 1 || $v === '1' || $v === 'true' || $v === 'on';
    }

    public function time(string $k, string $default): string
    {
        $v = $this->data[$k] ?? $default;
        if (!is_string($v) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v)) {
            $this->errors[$k] = 'Saat SS:DD biçiminde olmalı.';
            return $default;
        }
        return $v;
    }

    public function arr(string $k): array
    {
        $v = $this->data[$k] ?? [];
        return is_array($v) ? $v : [];
    }

    public function error(string $k, string $msg): void
    {
        $this->errors[$k] = $msg;
    }

    public function check(): void
    {
        if ($this->errors) {
            throw new HttpError(422, 'Lütfen işaretli alanları kontrol edin.', 'validation', $this->errors);
        }
    }
}
