<?php

declare(strict_types=1);

namespace Captchala\Tests;

use Captchala\Client;
use Captchala\IssueResult;
use Captchala\ModerationResult;
use Captchala\ValidateResult;
use PHPUnit\Framework\TestCase;

class ClientTest extends TestCase
{
    private Client $client;

    protected function setUp(): void
    {
        $this->client = new Client('test_app_key', 'test_app_secret');
    }

    public function testClientOnlyTokenIsValid(): void
    {
        $result = $this->client->validate('client_abc123xyz');

        $this->assertTrue($result->isValid());
        $this->assertTrue($result->isOffline());
        $this->assertTrue($result->isClientOnly());
        $this->assertNotNull($result->getWarning());
    }

    public function testEmptyTokenIsInvalid(): void
    {
        $result = $this->client->validate('');

        $this->assertFalse($result->isValid());
        $this->assertEquals('empty_token', $result->getError());
    }

    public function testMainTokenTypeDetection(): void
    {
        // pt_ token → main API. Use injected transport so no real network.
        $client = new Client('k', 's');
        $capturedUrl = null;
        $client->setTransport(function (string $url, array $body, array $headers) use (&$capturedUrl) {
            $capturedUrl = $url;
            return ['code' => 0, 'msg' => 'ok', 'data' => ['valid' => false, 'error' => 'token_expired']];
        });

        $result = $client->validate('pt_invalid_token');

        $this->assertFalse($result->isClientOnly());
        $this->assertFalse($result->isOffline());
        $this->assertEquals(Client::MAIN_API_URL, $capturedUrl);
    }

    public function testOfflineTokenTypeDetection(): void
    {
        $client = new Client('k', 's');
        $capturedUrl = null;
        $client->setTransport(function (string $url, array $body, array $headers) use (&$capturedUrl) {
            $capturedUrl = $url;
            return ['code' => 0, 'msg' => 'ok', 'data' => ['valid' => false, 'error' => 'x']];
        });

        $result = $client->validate('offline_invalid_token');

        $this->assertFalse($result->isClientOnly());
        $this->assertTrue($result->isOffline());
        $this->assertEquals(Client::BACKUP_API_URL, $capturedUrl);
    }

    public function testValidateResultToArray(): void
    {
        $result = new ValidateResult(
            valid: true,
            offline: false,
            clientOnly: false,
            challengeId: 'ch_123',
            action: 'login',
            uid: 'user_42',
        );

        $array = $result->toArray();

        $this->assertArrayHasKey('valid', $array);
        $this->assertArrayHasKey('offline', $array);
        $this->assertArrayHasKey('client_only', $array);
        $this->assertArrayHasKey('challenge_id', $array);
        $this->assertArrayHasKey('action', $array);
        $this->assertArrayHasKey('uid', $array);

        $this->assertTrue($array['valid']);
        $this->assertEquals('ch_123', $array['challenge_id']);
        $this->assertEquals('login', $array['action']);
        $this->assertEquals('user_42', $array['uid']);
    }

    public function testTokenPrefixConstants(): void
    {
        $this->assertEquals('pt_', Client::PREFIX_MAIN);
        $this->assertEquals('offline_', Client::PREFIX_OFFLINE);
        $this->assertEquals('client_', Client::PREFIX_CLIENT);
    }

    // --- Mock transport based tests -------------------------------------------

    public function testHappyPathParsesUidAndFields(): void
    {
        $client = new Client('k', 's');
        $capturedBody = null;
        $capturedHeaders = null;
        $client->setTransport(function (string $url, array $body, array $headers) use (&$capturedBody, &$capturedHeaders) {
            $capturedBody = $body;
            $capturedHeaders = $headers;
            return [
                'code' => 0,
                'msg' => 'ok',
                'data' => [
                    'valid' => true,
                    'challenge_id' => 'ch_abc',
                    'action' => 'login',
                    'uid' => 'user_42',
                ],
            ];
        });

        $result = $client->validate('pt_real_token');

        $this->assertTrue($result->isValid());
        $this->assertEquals('ch_abc', $result->getChallengeId());
        $this->assertEquals('login', $result->getAction());
        $this->assertEquals('user_42', $result->getUid());
        $this->assertFalse($result->isOffline());

        $this->assertSame('pt_real_token', $capturedBody['pass_token']);
        $this->assertFalse($capturedBody['keep_token']);
        $this->assertArrayNotHasKey('client_ip', $capturedBody);

        $this->assertContains('X-App-Key: k', $capturedHeaders);
        $this->assertContains('X-App-Secret: s', $capturedHeaders);
    }

    public function testValidateNeverSendsClientIp(): void
    {
        // The client_ip param is kept on the signature for backward compat but
        // is no longer transmitted: the dashboard doesn't gate on a
        // caller-supplied IP (cross-domain / dual-stack). It must never appear
        // in the request body, even when a caller passes one.
        $client = new Client('k', 's');
        $capturedBody = null;
        $client->setTransport(function (string $url, array $body, array $headers) use (&$capturedBody) {
            $capturedBody = $body;
            return ['code' => 0, 'msg' => 'ok', 'data' => ['valid' => true]];
        });

        $result = $client->validate('pt_x', false, '203.0.113.9');
        $this->assertTrue($result->isValid());
        $this->assertArrayNotHasKey('client_ip', $capturedBody);

        $capturedBody = null;
        $client->validate('pt_x', false, '');
        $this->assertArrayNotHasKey('client_ip', $capturedBody);

        $capturedBody = null;
        $client->validate('pt_x', false, null);
        $this->assertArrayNotHasKey('client_ip', $capturedBody);
    }

    public function testValidateParsesUserIpFromResponse(): void
    {
        $client = new Client('k', 's');
        $client->setTransport(function (string $url, array $body, array $headers) {
            return ['code' => 0, 'msg' => 'ok', 'data' => ['valid' => true, 'user_ip' => '198.51.100.7']];
        });

        $result = $client->validate('pt_x');
        $this->assertTrue($result->isValid());
        $this->assertSame('198.51.100.7', $result->getUserIp());
    }

    public function testNetworkErrorReturnsRequestFailed(): void
    {
        $client = new Client('k', 's');
        // Transport returning null signals transport failure.
        $client->setTransport(fn () => null);

        $result = $client->validate('pt_x');

        $this->assertFalse($result->isValid());
        $this->assertEquals('request_failed', $result->getError());
    }

    public function testBackendErrorPropagates(): void
    {
        $client = new Client('k', 's');
        $client->setTransport(fn () => [
            'code' => 0,
            'msg' => '',
            'data' => ['valid' => false, 'error' => 'token_expired'],
        ]);

        $result = $client->validate('pt_bad');

        $this->assertFalse($result->isValid());
        $this->assertEquals('token_expired', $result->getError());
    }

    public function testKeepTokenIsForwarded(): void
    {
        $client = new Client('k', 's');
        $capturedBody = null;
        $client->setTransport(function (string $url, array $body, array $headers) use (&$capturedBody) {
            $capturedBody = $body;
            return ['code' => 0, 'msg' => 'ok', 'data' => ['valid' => true]];
        });

        $client->validate('pt_x', true);

        $this->assertTrue($capturedBody['keep_token']);
    }

    public function testUidFieldOptional(): void
    {
        // Response without uid → getUid() returns null.
        $client = new Client('k', 's');
        $client->setTransport(fn () => [
            'code' => 0,
            'msg' => 'ok',
            'data' => ['valid' => true, 'challenge_id' => 'ch_x'],
        ]);

        $result = $client->validate('pt_ok');

        $this->assertTrue($result->isValid());
        $this->assertNull($result->getUid());
    }

    /**
     * @group integration
     */
    public function testRealValidation(): void
    {
        $appKey = getenv('CAPTCHALA_APP_KEY');
        $appSecret = getenv('CAPTCHALA_APP_SECRET');
        $token = getenv('CAPTCHALA_TEST_TOKEN');

        if (!$appKey || !$appSecret || !$token) {
            $this->markTestSkipped('Real credentials not provided');
        }

        $client = new Client($appKey, $appSecret);
        $result = $client->validate($token);

        $this->assertInstanceOf(ValidateResult::class, $result);
    }

    // ---------------- issueServerToken ----------------

    public function testIssueServerTokenEmptyActionRejected(): void
    {
        $r = $this->client->issueServerToken('');
        $this->assertFalse($r->isOk());
        $this->assertEquals('invalid_action', $r->getError());
    }

    public function testIssueServerTokenSendsCorrectBody(): void
    {
        $client = new Client('k', 's');
        $captured = null;
        $client->setTransport(function (string $url, array $body, array $headers) use (&$captured) {
            $captured = ['url' => $url, 'body' => $body, 'headers' => $headers];
            return ['code' => 0, 'msg' => 'success', 'data' => [
                'server_token' => 'sct_abc123', 'expires_in' => 300, 'issued_at' => 1700000000,
            ]];
        });
        $r = $client->issueServerToken('login', '1.2.3.4', 600, 5, 'user-42');
        $this->assertTrue($r->isOk());
        $this->assertEquals('sct_abc123', $r->getToken());
        $this->assertEquals(300, $r->getExpiresIn());
        $this->assertEquals(1700000000, $r->getIssuedAt());
        $this->assertEquals(Client::ISSUE_API_URL, $captured['url']);
        $this->assertEquals('login', $captured['body']['action']);
        $this->assertEquals('1.2.3.4', $captured['body']['binding_ip']);
        $this->assertEquals(600, $captured['body']['ttl']);
        $this->assertEquals(5, $captured['body']['max_uses']);
        $this->assertEquals('user-42', $captured['body']['bind_uid']);
        $this->assertContains('X-App-Key: k', $captured['headers']);
    }

    public function testIssueServerTokenOmitsOptionalParams(): void
    {
        $client = new Client('k', 's');
        $captured = null;
        $client->setTransport(function (string $url, array $body, array $headers) use (&$captured) {
            $captured = $body;
            return ['code' => 0, 'msg' => 'success', 'data' => ['server_token' => 'sct_x']];
        });
        $client->issueServerToken('register');
        $this->assertEquals(['action' => 'register'], $captured);
    }

    public function testIssueServerTokenSurfacesError(): void
    {
        $client = new Client('k', 's');
        $client->setTransport(fn() => ['code' => 1001, 'msg' => 'rate limit', 'error' => 'rate_limit_exceeded']);
        $r = $client->issueServerToken('login');
        $this->assertFalse($r->isOk());
        $this->assertEquals('rate_limit_exceeded', $r->getError());
        $this->assertEquals('rate limit', $r->getMessage());
    }

    public function testIssueServerTokenTransportFailure(): void
    {
        $client = new Client('k', 's');
        $client->setTransport(fn() => null);
        $r = $client->issueServerToken('login');
        $this->assertFalse($r->isOk());
        $this->assertEquals('request_failed', $r->getError());
    }

    // ---------------- moderationCheck ----------------

    public function testModerationCheckEmptyInputRejected(): void
    {
        $r = $this->client->moderationCheck([]);
        $this->assertFalse($r->isOk());
        $this->assertEquals('empty_input', $r->getError());
    }

    public function testModerationCheckMixedTextAndImage(): void
    {
        $client = new Client('k', 's');
        $captured = null;
        $client->setTransport(function (string $url, array $body, array $headers) use (&$captured) {
            $captured = ['url' => $url, 'body' => $body];
            return ['code' => 0, 'msg' => 'ok', 'data' => [
                'flagged' => true,
                'categories' => ['violence' => true, 'hate' => false],
                'content_type' => 'mixed',
            ]];
        });
        $input = [
            ['type' => 'text', 'text' => 'hello'],
            ['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/x.jpg']],
        ];
        $r = $client->moderationCheck($input, 'user-42');
        $this->assertTrue($r->isOk());
        $this->assertTrue($r->isFlagged());
        $this->assertTrue($r->hasCategory('violence'));
        $this->assertFalse($r->hasCategory('hate'));
        $this->assertTrue($r->hasCategory('hate', 'violence'));    // any of the two
        $this->assertFalse($r->hasCategory('csam'));
        $this->assertEquals('mixed', $r->getContentType());
        $this->assertEquals(Client::MODERATION_CHECK_URL, $captured['url']);
        $this->assertEquals('k', $captured['body']['app_key']);
        $this->assertEquals('s', $captured['body']['app_secret']);
        $this->assertEquals($input, $captured['body']['input']);
        $this->assertEquals('user-42', $captured['body']['user_id']);
    }

    public function testModerationCheckSurfacesError(): void
    {
        $client = new Client('k', 's');
        $client->setTransport(fn() => ['code' => 401, 'msg' => 'bad creds', 'error' => 'invalid_credentials']);
        $r = $client->moderationCheck([['type' => 'text', 'text' => 'x']]);
        $this->assertFalse($r->isOk());
        $this->assertEquals('invalid_credentials', $r->getError());
        $this->assertEquals('bad creds', $r->getMessage());
    }

    public function testModerationCheckTransportFailure(): void
    {
        $client = new Client('k', 's');
        $client->setTransport(fn() => null);
        $r = $client->moderationCheck([['type' => 'text', 'text' => 'x']]);
        $this->assertFalse($r->isOk());
        $this->assertEquals('request_failed', $r->getError());
    }

    // ---------------- moderationText ----------------

    public function testModerationTextEmptyTextRejected(): void
    {
        $r = $this->client->moderationText('');
        $this->assertFalse($r->isOk());
        $this->assertEquals('empty_text', $r->getError());
    }

    public function testModerationTextSendsBody(): void
    {
        $client = new Client('k', 's');
        $captured = null;
        $client->setTransport(function (string $url, array $body, array $headers) use (&$captured) {
            $captured = ['url' => $url, 'body' => $body];
            return ['code' => 0, 'msg' => 'ok', 'data' => [
                'flagged' => false, 'categories' => [], 'content_type' => 'text',
            ]];
        });
        $r = $client->moderationText('hello world');
        $this->assertTrue($r->isOk());
        $this->assertFalse($r->isFlagged());
        $this->assertEquals('text', $r->getContentType());
        $this->assertEquals(Client::MODERATION_TEXT_URL, $captured['url']);
        $this->assertEquals('hello world', $captured['body']['text']);
        $this->assertArrayNotHasKey('user_id', $captured['body']);
    }
}
