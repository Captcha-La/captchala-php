<?php
declare(strict_types=1);

namespace Captchala\Cms;

/**
 * Maps backend error codes to user-friendly messages displayed by CMS
 * plugins inside form-validation errors. The dash backend's authoritative
 * codes live in app/controller/ChallengeController.php and
 * app/service/ChallengeService.php. Unknown / null / empty codes fall back
 * to the generic verification message — never expose raw codes to the
 * end user.
 *
 * The strings here are English-only; CMS plugins translate them through
 * their host i18n stack (gettext, ICU, etc.) using these strings as the
 * source language.
 */
final class Errors
{
    private const GENERIC = 'CAPTCHA verification did not pass. Please try again.';

    /** @var array<string,string> */
    private const MAP = [
        'invalid_token'             => self::GENERIC,
        'empty_token'               => self::GENERIC,
        'unknown_error'             => self::GENERIC,
        'token_consumed'            => 'CAPTCHA token already used. Please refresh and try again.',
        'token_expired'             => 'CAPTCHA token expired. Please refresh and try again.',
        'token_action_mismatch'     => 'CAPTCHA was solved for a different action. Please refresh.',
        'token_ip_mismatch'         => 'CAPTCHA was solved from a different network. Please refresh.',
        'token_uid_mismatch'        => 'CAPTCHA was not solved by the expected user.',
        'request_failed'            => 'CAPTCHA service is temporarily unavailable. Please try again shortly.',
        'rate_limit_exceeded'       => 'Too many CAPTCHA attempts. Please wait a moment.',
        'invalid_action'            => self::GENERIC,
        'invalid_app_key'           => 'CAPTCHA configuration error — please contact the site owner.',
        'invalid_app_secret'        => 'CAPTCHA configuration error — please contact the site owner.',
        'app_disabled'              => 'CAPTCHA is currently disabled for this site.',
    ];

    public static function standardize(?string $code): string
    {
        if ($code === null || $code === '') {
            return self::GENERIC;
        }
        return self::MAP[$code] ?? self::GENERIC;
    }

    private function __construct() {}
}
