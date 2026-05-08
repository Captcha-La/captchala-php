<?php
declare(strict_types=1);

namespace Captchala\Cms;

/**
 * Canonical action names recognised by the CaptchaLa dash backend's
 * per-action security policy gate. CMS plugins pass one of these as the
 * `action` argument to `Client::issueServerToken()` and as the `data-action`
 * attribute on the rendered widget.
 *
 * Kept as a final class with string constants (not a PHP 8.1 enum) so that
 * the SDK keeps its PHP 8.0 minimum from composer.json.
 */
final class Action
{
    public const LOGIN          = 'login';
    public const REGISTER       = 'register';
    public const LOST_PASSWORD  = 'lost_password';
    public const RESET_PASSWORD = 'reset_password';
    public const COMMENT        = 'comment';
    public const CHECKOUT       = 'checkout';
    public const PAY_FOR_ORDER  = 'pay_for_order';
    public const ACCOUNT_CREATE = 'account_create';
    public const FORUM_POST     = 'forum_post';
    public const FORUM_REGISTER = 'forum_register';
    public const PM_SEND        = 'pm_send';
    public const CONTACT_FORM   = 'contact_form';
    public const GENERIC_FORM   = 'generic_form';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::LOGIN, self::REGISTER, self::LOST_PASSWORD, self::RESET_PASSWORD,
            self::COMMENT, self::CHECKOUT, self::PAY_FOR_ORDER, self::ACCOUNT_CREATE,
            self::FORUM_POST, self::FORUM_REGISTER, self::PM_SEND,
            self::CONTACT_FORM, self::GENERIC_FORM,
        ];
    }

    public static function isValid(string $action): bool
    {
        return in_array($action, self::all(), true);
    }

    private function __construct() {}
}
