<?php
declare(strict_types=1);

namespace Dnl\Controllers;

defined('DNL_APP') || exit;

use Dnl\Core\Http;
use Dnl\Core\Input;
use Dnl\Core\RateLimiter;
use Dnl\Services\Otp;

final class OtpController
{
    public static function send(): array
    {
        $in = new Input(Http::json());
        $phone = $in->phone('phone');
        $purpose = $in->str('purpose', 3, 30, true, 'Amaç');
        $in->check();
        return Otp::request($phone, $purpose, Http::ip());
    }

    public static function verify(): array
    {
        RateLimiter::enforce('otp_verify:ip:' . Http::ip(), 30, 900);
        $in = new Input(Http::json());
        $phone = $in->phone('phone');
        $purpose = $in->str('purpose', 3, 30, true, 'Amaç');
        $code = $in->raw('code', 6, 6, 'Doğrulama kodu');
        $in->check();
        return Otp::verify($phone, $purpose, $code);
    }
}
