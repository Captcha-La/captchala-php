<?php
declare(strict_types=1);

namespace Captchala\Tests\Cms;

use Captchala\Cms\Widget;
use Captchala\Cms\Action;
use PHPUnit\Framework\TestCase;

class WidgetTest extends TestCase
{
    public function testRenderProducesContainerAndScript(): void
    {
        $html = Widget::renderHtml('myappkey', 'sct_abc123', Action::LOGIN);

        $this->assertStringContainsString('data-captchala', $html);
        $this->assertStringContainsString('data-app-key="myappkey"', $html);
        $this->assertStringContainsString('data-server-token="sct_abc123"', $html);
        $this->assertStringContainsString('data-action="login"', $html);
        $this->assertStringContainsString('cdn.captcha-cdn.net/captchala-loader.js', $html);
        $this->assertStringContainsString('<script', $html);
    }

    public function testRenderEscapesAttributeValues(): void
    {
        $html = Widget::renderHtml('app"key', 'sct_<x>', 'login');

        $this->assertStringNotContainsString('app"key"', $html);
        $this->assertStringNotContainsString('sct_<x>', $html);
        $this->assertStringContainsString('data-app-key="app&quot;key"', $html);
        $this->assertStringContainsString('data-server-token="sct_&lt;x&gt;"', $html);
    }

    public function testRenderRejectsInvalidAction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Widget::renderHtml('appkey', 'sct_x', 'made_up_action');
    }

    public function testRenderHonoursOptionalProductMode(): void
    {
        $html = Widget::renderHtml('k', 'sct_x', Action::LOGIN, ['product' => 'bind']);
        $this->assertStringContainsString('data-product="bind"', $html);
    }

    public function testRenderHonoursOptionalLang(): void
    {
        $html = Widget::renderHtml('k', 'sct_x', Action::LOGIN, ['lang' => 'ja']);
        $this->assertStringContainsString('data-lang="ja"', $html);
    }

    public function testRenderEmitsHiddenInputWhenAsked(): void
    {
        $html = Widget::renderHtml('k', 'sct_x', Action::LOGIN, ['hidden_input' => true]);
        $this->assertStringContainsString('<input type="hidden"', $html);
        $this->assertStringContainsString('name="captchala_token"', $html);
    }
}
