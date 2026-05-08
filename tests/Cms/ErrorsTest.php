<?php
declare(strict_types=1);

namespace Captchala\Tests\Cms;

use Captchala\Cms\Errors;
use PHPUnit\Framework\TestCase;

class ErrorsTest extends TestCase
{
    public function testStandardizeKnownCodes(): void
    {
        $this->assertSame(
            'CAPTCHA verification did not pass. Please try again.',
            Errors::standardize('invalid_token')
        );
        $this->assertSame(
            'CAPTCHA token already used. Please refresh and try again.',
            Errors::standardize('token_consumed')
        );
        $this->assertSame(
            'CAPTCHA token expired. Please refresh and try again.',
            Errors::standardize('token_expired')
        );
        $this->assertSame(
            'CAPTCHA service is temporarily unavailable. Please try again shortly.',
            Errors::standardize('request_failed')
        );
    }

    public function testStandardizeUnknownCodeFallsBackToGeneric(): void
    {
        $this->assertSame(
            'CAPTCHA verification did not pass. Please try again.',
            Errors::standardize('made_up_error')
        );
    }

    public function testStandardizeNullOrEmptyFallsBackToGeneric(): void
    {
        $this->assertSame(
            'CAPTCHA verification did not pass. Please try again.',
            Errors::standardize(null)
        );
        $this->assertSame(
            'CAPTCHA verification did not pass. Please try again.',
            Errors::standardize('')
        );
    }
}
