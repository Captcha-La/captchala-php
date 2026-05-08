<?php
declare(strict_types=1);

namespace Captchala\Tests\Cms;

use Captchala\Cms\Action;
use PHPUnit\Framework\TestCase;

class ActionTest extends TestCase
{
    public function testCanonicalConstantsExist(): void
    {
        $this->assertSame('login', Action::LOGIN);
        $this->assertSame('register', Action::REGISTER);
        $this->assertSame('lost_password', Action::LOST_PASSWORD);
        $this->assertSame('reset_password', Action::RESET_PASSWORD);
        $this->assertSame('comment', Action::COMMENT);
        $this->assertSame('checkout', Action::CHECKOUT);
        $this->assertSame('pay_for_order', Action::PAY_FOR_ORDER);
        $this->assertSame('account_create', Action::ACCOUNT_CREATE);
        $this->assertSame('forum_post', Action::FORUM_POST);
        $this->assertSame('forum_register', Action::FORUM_REGISTER);
        $this->assertSame('pm_send', Action::PM_SEND);
        $this->assertSame('contact_form', Action::CONTACT_FORM);
        $this->assertSame('generic_form', Action::GENERIC_FORM);
    }

    public function testAllReturnsEveryConstant(): void
    {
        $all = Action::all();
        $this->assertCount(13, $all);
        $this->assertContains('login', $all);
        $this->assertContains('generic_form', $all);
    }

    public function testIsValidAcceptsCanonicalAndRejectsOthers(): void
    {
        $this->assertTrue(Action::isValid('login'));
        $this->assertFalse(Action::isValid('LOGIN'));
        $this->assertFalse(Action::isValid('made_up_action'));
        $this->assertFalse(Action::isValid(''));
    }
}
