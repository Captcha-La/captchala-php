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

    /** @var resource|null */
    private static $server = null;
    private static int $serverPort = 0;

    protected function tearDown(): void
    {
        Client::resetReachabilityCache();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            proc_terminate(self::$server);
            proc_close(self::$server);
            self::$server = null;
        }
        @unlink(sys_get_temp_dir() . '/captchala_probe_router_' . getmypid() . '.php');
    }

    /**
     * Start a PHP built-in web server that answers every request with a
     * non-JSON 503 (any HTTP response counts as "reachable").
     */
    private static function startServer(): string
    {
        if (self::$server === null) {
            $router = sys_get_temp_dir() . '/captchala_probe_router_' . getmypid() . '.php';
            file_put_contents($router, '<?php http_response_code(503); echo "service_online";');
            $sock = stream_socket_server('tcp://127.0.0.1:0');
            $name = stream_socket_get_name($sock, false);
            fclose($sock);
            self::$serverPort = (int)substr($name, strrpos($name, ':') + 1);
            self::$server = proc_open(
                [PHP_BINARY, '-S', '127.0.0.1:' . self::$serverPort, $router],
                [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
                $pipes
            );
            for ($i = 0; $i < 50; $i++) {
                $fp = @fsockopen('127.0.0.1', self::$serverPort, $errno, $errstr, 0.1);
                if ($fp) {
                    fclose($fp);
                    break;
                }
                usleep(100000);
            }
        }
        return 'http://127.0.0.1:' . self::$serverPort . '/validate';
    }

    public static function clientOnlyTokens(): array
    {
        return [['client_abc123xyz'], ['co_abc123xyz']];
    }

    /** @dataProvider clientOnlyTokens */
    public function testClientOnlyTokenAcceptedWhenBothApisUnreachable(string $token): void
    {
        $client = new Client('k', 's');
        $client->setBaseUrl('https://main.invalid/v1/validate');
        $client->setBackupUrl('https://backup.invalid/api/validate');
        $client->setTransport(fn () => null); // every request fails at transport level

        $result = $client->validate($token);

        $this->assertTrue($result->isValid());
        $this->assertTrue($result->isOffline());
        $this->assertTrue($result->isClientOnly());
        $this->assertNotNull($result->getWarning());
    }

    /** @dataProvider clientOnlyTokens */
    public function testClientOnlyTokenRejectedWhenMainReachable(string $token): void
    {
        $client = new Client('k', 's');
        $urls = [];
        $client->setTransport(function (string $url) use (&$urls) {
            $urls[] = $url;
            return ['code' => 400, 'msg' => 'bad token'];
        });

        $result = $client->validate($token);

        $this->assertFalse($result->isValid());
        $this->assertTrue($result->isClientOnly());
        $this->assertSame(Client::ERROR_CLIENT_ONLY_REJECTED, $result->getError());
        $this->assertSame([Client::MAIN_API_URL], $urls);
    }

    public function testClientOnlyTokenRejectedWhenOnlyBackupReachable(): void
    {
        $client = new Client('k', 's');
        $urls = [];
        $client->setTransport(function (string $url) use (&$urls) {
            $urls[] = $url;
            return $url === Client::BACKUP_API_URL ? ['code' => 503, 'msg' => 'service_online'] : null;
        });

        $result = $client->validate('client_x');

        $this->assertFalse($result->isValid());
        $this->assertTrue($result->isClientOnly());
        $this->assertSame(Client::ERROR_CLIENT_ONLY_REJECTED, $result->getError());
        $this->assertSame([Client::MAIN_API_URL, Client::BACKUP_API_URL], $urls);
    }

    public function testClientOnlyReachabilityIsCached(): void
    {
        $client = new Client('k', 's');
        $calls = 0;
        $client->setTransport(function () use (&$calls) {
            $calls++;
            return ['code' => 400];
        });

        for ($i = 0; $i < 5; $i++) {
            $this->assertFalse($client->validate('client_x')->isValid());
        }
        $this->assertSame(1, $calls);
    }

    public function testClientOnlyRealHttpUnreachable(): void
    {
        if (!function_exists('curl_init')) {
            $this->markTestSkipped('curl not available');
        }
        $client = new Client('k', 's');
        $client->setBaseUrl('http://127.0.0.1:1/v1/validate');
        $client->setBackupUrl('http://127.0.0.1:2/api/validate');

        $result = $client->validate('client_x');

        $this->assertTrue($result->isValid());
        $this->assertTrue($result->isClientOnly());
    }

    public function testClientOnlyRealHttpNonJson503CountsAsReachable(): void
    {
        $url = self::startServer();
        $client = new Client('k', 's');
        $client->setBaseUrl('http://127.0.0.1:1/v1/validate');
        $client->setBackupUrl($url);

        $result = $client->validate('co_x');

        $this->assertFalse($result->isValid());
        $this->assertTrue($result->isClientOnly());
        $this->assertSame(Client::ERROR_CLIENT_ONLY_REJECTED, $result->getError());
    }

    public function testNonJsonHttpResponseIsRequestFailed(): void
    {
        $url = self::startServer();
        $client = new Client('k', 's');
        $client->setBaseUrl($url);

        $result = $client->validate('pt_x');

        $this->assertFalse($result->isValid());
        $this->assertSame('request_failed', $result->getError());
    }

    public function testNonArrayJsonBodyDoesNotThrow(): void
    {
        $client = new Client('k', 's');
        $client->setTransport(fn () => ['code' => 0, 'data' => 'oops']);
        $this->assertFalse($client->validate('pt_x')->isValid());

        $client->setTransport(fn () => 'not-an-array');
        $this->assertSame('request_failed', $client->validate('pt_x')->getError());

        $client->setTransport(fn () => ['code' => 1, 'error' => 42, 'msg' => 7, 'data' => 5]);
        $issue = $client->issueServerToken('login');
        $this->assertFalse($issue->isOk());

        $mod = $client->moderationText('hi');
        $this->assertFalse($mod->isOk());
    }

    public function testIsClientOnlyToken(): void
    {
        $this->assertTrue(Client::isClientOnlyToken('client_a'));
        $this->assertTrue(Client::isClientOnlyToken('co_a'));
        $this->assertFalse(Client::isClientOnlyToken('pt_a'));
        $this->assertFalse(Client::isClientOnlyToken('offline_a'));
        $this->assertFalse(Client::isClientOnlyToken('dg_a'));
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
        $this->assertEquals('co_', Client::PREFIX_CLIENT_WEB);
        $this->assertEquals('dg_', Client::PREFIX_DEGRADED);
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

    public function testValidateSendsClientIpWhenProvided(): void
    {
        // client_ip is optional: sent when provided (used for risk checks),
        // omitted when empty/null.
        $client = new Client('k', 's');
        $capturedBody = null;
        $client->setTransport(function (string $url, array $body, array $headers) use (&$capturedBody) {
            $capturedBody = $body;
            return ['code' => 0, 'msg' => 'ok', 'data' => ['valid' => true]];
        });

        $result = $client->validate('pt_x', false, '203.0.113.9');
        $this->assertTrue($result->isValid());
        $this->assertSame('203.0.113.9', $capturedBody['client_ip']);

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
