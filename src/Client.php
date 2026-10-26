<?php

declare(strict_types=1);

namespace Captchala;

/**
 * Captchala Server SDK Client
 *
 * Server-side validation for Captcha tokens
 *
 * @example
 * ```php
 * use Captchala\Client;
 *
 * $client = new Client('your_app_key', 'your_app_secret');
 * $result = $client->validate($token);
 *
 * if ($result->isValid()) {
 *     // Verification passed
 * } else {
 *     // Verification failed: $result->getError()
 * }
 * ```
 */
class Client
{
    private string $appKey;
    private string $appSecret;
    private int $timeout;

    // API endpoints
    public const MAIN_API_URL = 'https://apiv1.captcha.la/v1/validate';
    public const BACKUP_API_URL = 'https://fallbackapiv1.captchala.com/api/validate';
    public const ISSUE_API_URL = 'https://apiv1.captcha.la/v1/server/challenge/issue';
    public const MODERATION_CHECK_URL = 'https://apiv1.captcha.la/v1/moderation/check';
    public const MODERATION_TEXT_URL = 'https://apiv1.captcha.la/v1/moderation/text';

    // Token prefixes
    public const PREFIX_MAIN = 'pt_';        // Main API token
    public const PREFIX_OFFLINE = 'offline_'; // Backup/offline token
    public const PREFIX_CLIENT = 'client_';   // Client-only token (native SDKs)
    public const PREFIX_CLIENT_WEB = 'co_';   // Client-only token (web SDK), handled like client_
    public const PREFIX_DEGRADED = 'dg_';     // Degraded token (e.g. quota exhausted), see isDegraded()

    /** Error code returned when a client-only token arrives while the API is reachable. */
    public const ERROR_CLIENT_ONLY_REJECTED = 'client_only_rejected_api_reachable';

    /** Reachability probe timeout (seconds) used for client-only tokens. */
    public const PROBE_TIMEOUT = 2;

    /** How long (seconds) a positive reachability probe is cached in-process. */
    public const REACHABILITY_CACHE_TTL = 30;

    /**
     * Per-URL unix timestamp until which the endpoint is known reachable.
     * Only positive results are cached.
     *
     * @var array<string,float>
     */
    private static array $reachableUntil = [];

    /**
     * Optional override for the main API endpoint. Mainly used for tests.
     */
    private ?string $baseUrl = null;

    /**
     * Optional override for the backup API endpoint. Mainly used for tests.
     */
    private ?string $backupUrl = null;

    /** Optional URL overrides for the new endpoints. Mainly used for tests. */
    private ?string $issueUrl = null;
    private ?string $moderationCheckUrl = null;
    private ?string $moderationTextUrl = null;

    /**
     * Optional transport override. If set, must be callable matching:
     *   fn(string $url, array $body, array $headers): ?array
     * Returning null signals a transport error (handled as 'request_failed').
     *
     * @var callable|null
     */
    private $transport = null;

    /**
     * Create a Captchala client
     *
     * @param string $appKey App Key (from dashboard)
     * @param string $appSecret App Secret (from dashboard)
     * @param int $timeout Request timeout in seconds
     */
    public function __construct(string $appKey, string $appSecret, int $timeout = 5)
    {
        $this->appKey = $appKey;
        $this->appSecret = $appSecret;
        $this->timeout = $timeout;
    }

    /**
     * Override the main API URL. Intended for tests.
     */
    public function setBaseUrl(?string $url): void
    {
        $this->baseUrl = $url;
    }

    /**
     * Override the backup API URL. Intended for tests.
     */
    public function setBackupUrl(?string $url): void
    {
        $this->backupUrl = $url;
    }

    public function setIssueUrl(?string $url): void { $this->issueUrl = $url; }
    public function setModerationCheckUrl(?string $url): void { $this->moderationCheckUrl = $url; }
    public function setModerationTextUrl(?string $url): void { $this->moderationTextUrl = $url; }

    /**
     * Inject a custom transport. Intended for tests.
     *
     * Signature: fn(string $url, array $body, array $headers): ?array
     */
    public function setTransport(?callable $transport): void
    {
        $this->transport = $transport;
    }

    /**
     * Validate a token.
     *
     * @param string $token The pass_token from SDK
     * @param bool $keepToken If true, token won't be consumed (can be validated again)
     * @param string|null $clientIp End-user IP for bind_ip verification. If the pass_token
     *                              was issued with bind_ip, pass the real user IP here so the
     *                              backend can verify it. Pass null to skip.
     * @return ValidateResult
     */
    public function validate(string $token, bool $keepToken = false, ?string $clientIp = null): ValidateResult
    {
        // Empty token
        if (empty($token)) {
            return new ValidateResult(false, false, false, 'empty_token');
        }

        // Client-only placeholders (client_ from native SDKs, co_ from the web
        // SDK) are minted on the device only when BOTH the main API and the
        // backup service were unreachable. They carry no signature and no
        // server record, so they are accepted only if this server cannot reach
        // either endpoint either (a genuine total outage).
        if (self::isClientOnlyToken($token)) {
            return $this->validateClientOnly();
        }

        // Select API based on token prefix
        if (str_starts_with($token, self::PREFIX_OFFLINE)) {
            // offline_ prefix -> backup API
            $apiUrl = $this->backupUrl ?? self::BACKUP_API_URL;
            $isOffline = true;
        } else {
            // pt_ prefix or any other -> main API
            $apiUrl = $this->baseUrl ?? self::MAIN_API_URL;
            $isOffline = false;
        }

        // Build request body. $clientIp is OPTIONAL but RECOMMENDED — pass the
        // end-user's IP from your inbound request; it is used for additional
        // risk checks. Safe to omit.
        $body = [
            'pass_token' => $token,
            'keep_token' => $keepToken,
        ];
        if ($clientIp !== null && $clientIp !== '') {
            $body['client_ip'] = $clientIp;
        }

        $response = $this->request($apiUrl, $body);

        if ($response === null) {
            return new ValidateResult(false, $isOffline, false, 'request_failed');
        }

        // Parse response
        $code = $response['code'] ?? -1;
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];

        if ($code === 0 && ($data['valid'] ?? $data['ok'] ?? false)) {
            return new ValidateResult(
                valid: true,
                offline: $isOffline,
                clientOnly: false,
                challengeId: self::strOrNull($data['challenge_id'] ?? null),
                action: self::strOrNull($data['action'] ?? null),
                uid: isset($data['uid']) && is_string($data['uid']) ? $data['uid'] : null,
                // user_ip lives inside captcha_args (Geetest-style); accept a
                // top-level user_ip too for forward/backward tolerance.
                userIp: self::extractUserIp($data),
                captchaArgs: isset($data['captcha_args']) && is_array($data['captcha_args']) ? $data['captcha_args'] : [],
            );
        }

        // Degraded token (dg_ prefix): issued when the app's quota is exhausted
        // so end-user flows are not interrupted. valid is ALWAYS false (secure
        // default); the integrator decides whether to accept via isDegraded().
        if (!empty($data['degraded'])) {
            return new ValidateResult(
                valid: false,
                offline: $isOffline,
                clientOnly: false,
                error: self::strOrNull($data['error'] ?? null),
                degraded: true,
                degradedReason: isset($data['reason']) && is_string($data['reason']) ? $data['reason'] : 'quota_exhausted',
            );
        }

        $error = $data['error'] ?? $response['msg'] ?? 'unknown_error';
        return new ValidateResult(false, $isOffline, false, self::strOrNull($error) ?? 'unknown_error');
    }

    /**
     * Coerce a scalar JSON value to string; anything else becomes null.
     * Keeps strict_types from throwing TypeError on unexpected payloads.
     */
    private static function strOrNull(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        return null;
    }

    /**
     * Whether the token is a device-minted client-only placeholder (client_ or co_).
     */
    public static function isClientOnlyToken(string $token): bool
    {
        return str_starts_with($token, self::PREFIX_CLIENT)
            || str_starts_with($token, self::PREFIX_CLIENT_WEB);
    }

    /**
     * Decide a client-only token by probing reachability:
     * main API reachable -> reject; else backup API reachable -> reject (the
     * device should have obtained an offline_ token); both unreachable -> accept.
     */
    private function validateClientOnly(): ValidateResult
    {
        if ($this->probeReachable($this->baseUrl ?? self::MAIN_API_URL)) {
            return new ValidateResult(
                valid: false,
                offline: true,
                clientOnly: true,
                error: self::ERROR_CLIENT_ONLY_REJECTED,
                warning: 'Client-only token rejected: main API is reachable'
            );
        }
        if ($this->probeReachable($this->backupUrl ?? self::BACKUP_API_URL)) {
            return new ValidateResult(
                valid: false,
                offline: true,
                clientOnly: true,
                error: self::ERROR_CLIENT_ONLY_REJECTED,
                warning: 'Client-only token rejected: backup API is reachable'
            );
        }
        return new ValidateResult(
            valid: true,
            offline: true,
            clientOnly: true,
            warning: 'Client-only token accepted: Captchala API unreachable, token cannot be verified server-side'
        );
    }

    /**
     * Clear the in-process reachability cache.
     *
     * @internal Intended for tests.
     */
    public static function resetReachabilityCache(): void
    {
        self::$reachableUntil = [];
    }

    /**
     * Whether $url answers with ANY HTTP response within PROBE_TIMEOUT seconds.
     * Transport errors and timeouts mean unreachable.
     */
    private function probeReachable(string $url): bool
    {
        $now = microtime(true);
        if (isset(self::$reachableUntil[$url]) && self::$reachableUntil[$url] > $now) {
            return true;
        }

        $body = ['pass_token' => '', 'keep_token' => true];
        $headers = [
            'Content-Type: application/json',
            'X-App-Key: ' . $this->appKey,
            'X-App-Secret: ' . $this->appSecret,
        ];

        if ($this->transport !== null) {
            // Injected transport: null means transport failure, anything else is a response.
            $reachable = ($this->transport)($url, $body, $headers) !== null;
        } elseif (function_exists('curl_init')) {
            $reachable = $this->rawRequestWithCurl($url, $body, $headers, self::PROBE_TIMEOUT) !== null;
        } else {
            $reachable = $this->rawRequestWithStream($url, $body, $headers, self::PROBE_TIMEOUT) !== null;
        }

        if ($reachable) {
            self::$reachableUntil[$url] = microtime(true) + self::REACHABILITY_CACHE_TTL;
        }
        return $reachable;
    }

    /**
     * The dashboard returns the solve-time user IP inside `captcha_args`
     * (Geetest-style). Older responses had it top-level; accept either.
     *
     * @param array<string,mixed> $data
     */
    private static function extractUserIp(array $data): ?string
    {
        $args = $data['captcha_args'] ?? null;
        if (is_array($args) && isset($args['user_ip']) && is_string($args['user_ip'])) {
            return $args['user_ip'];
        }
        if (isset($data['user_ip']) && is_string($data['user_ip'])) {
            return $data['user_ip'];
        }
        return null;
    }

    /**
     * Issue a one-time server_token for the given action.
     *
     * Hand the returned `sct_<hex>` token to the browser SDK via the
     * `serverToken` prop. Each token is single-use, scoped to one action,
     * and binds at issuance time to the IP / uid you pass in (if any).
     *
     * @param string      $action     Business scene (e.g. "login", "register").
     * @param string|null $bindingIp  End-user IP — backend rejects the token
     *                                if a different IP redeems it. Pass null to skip.
     * @param int|null    $ttl        Lifetime in seconds. Server enforces a hard
     *                                upper bound; defaults to 300 (5 min).
     * @param int|null    $maxUses    SDK retry budget. Tokens are still single-pass
     *                                for verification.
     * @param string|null $bindUid    User ID to bind. Pair with ValidateResult::getUid()
     *                                on the verify side to confirm the same user.
     */
    public function issueServerToken(
        string $action,
        ?string $bindingIp = null,
        ?int $ttl = null,
        ?int $maxUses = null,
        ?string $bindUid = null
    ): IssueResult {
        if ($action === '') {
            return new IssueResult(false, error: 'invalid_action', message: 'action is required');
        }
        $body = ['action' => $action];
        if ($bindingIp !== null && $bindingIp !== '') $body['binding_ip'] = $bindingIp;
        if ($ttl !== null)                            $body['ttl'] = $ttl;
        if ($maxUses !== null)                        $body['max_uses'] = $maxUses;
        if ($bindUid !== null && $bindUid !== '')     $body['bind_uid'] = $bindUid;

        $url = $this->issueUrl ?? self::ISSUE_API_URL;
        $response = $this->request($url, $body);
        if ($response === null) {
            return new IssueResult(false, error: 'request_failed', message: 'transport error');
        }
        $code = $response['code'] ?? -1;
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        if ($code === 0 && !empty($data['server_token'])) {
            return new IssueResult(
                ok: true,
                token: (string)$data['server_token'],
                expiresIn: isset($data['expires_in']) ? (int)$data['expires_in'] : null,
                issuedAt: isset($data['issued_at']) ? (int)$data['issued_at'] : null,
            );
        }
        return new IssueResult(
            ok: false,
            error: self::strOrNull($response['error'] ?? $data['error'] ?? null) ?? 'unknown_error',
            message: self::strOrNull($response['msg'] ?? $response['message'] ?? null),
        );
    }

    /**
     * Multi-modal content moderation. Accepts a mix of text and image_url
     * items in OpenAI-compatible format:
     *
     *   $client->moderationCheck([
     *       ['type' => 'text', 'text' => 'some content'],
     *       ['type' => 'image_url', 'image_url' => ['url' => 'https://...']],
     *   ]);
     *
     * @param array       $input   Array of {type, text|image_url} items.
     * @param string|null $userId  Optional end-user identifier for rate limiting.
     */
    public function moderationCheck(array $input, ?string $userId = null): ModerationResult
    {
        if (empty($input)) {
            return new ModerationResult(false, error: 'empty_input', message: 'input is required');
        }
        $body = [
            'app_key' => $this->appKey,
            'app_secret' => $this->appSecret,
            'input' => $input,
        ];
        if ($userId !== null && $userId !== '') $body['user_id'] = $userId;

        $url = $this->moderationCheckUrl ?? self::MODERATION_CHECK_URL;
        $response = $this->request($url, $body);
        return $this->parseModerationResponse($response);
    }

    /**
     * Convenience wrapper for plain-text moderation. Equivalent to calling
     * moderationCheck() with a single text item.
     */
    public function moderationText(string $text, ?string $userId = null): ModerationResult
    {
        if ($text === '') {
            return new ModerationResult(false, error: 'empty_text', message: 'text is required');
        }
        $body = [
            'app_key' => $this->appKey,
            'app_secret' => $this->appSecret,
            'text' => $text,
        ];
        if ($userId !== null && $userId !== '') $body['user_id'] = $userId;

        $url = $this->moderationTextUrl ?? self::MODERATION_TEXT_URL;
        $response = $this->request($url, $body);
        return $this->parseModerationResponse($response);
    }

    private function parseModerationResponse(?array $response): ModerationResult
    {
        if ($response === null) {
            return new ModerationResult(false, error: 'request_failed', message: 'transport error');
        }
        $code = $response['code'] ?? -1;
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        if ($code === 0) {
            return new ModerationResult(
                ok: true,
                flagged: (bool)($data['flagged'] ?? false),
                categories: is_array($data['categories'] ?? null) ? $data['categories'] : [],
                raw: $data,
                contentType: isset($data['content_type']) ? (string)$data['content_type'] : null,
            );
        }
        return new ModerationResult(
            ok: false,
            error: self::strOrNull($response['error'] ?? $data['error'] ?? null) ?? 'unknown_error',
            message: self::strOrNull($response['msg'] ?? $response['message'] ?? null),
        );
    }

    /**
     * Make HTTP request
     */
    private function request(string $url, array $data): ?array
    {
        $headers = [
            'Content-Type: application/json',
            'X-App-Key: ' . $this->appKey,
            'X-App-Secret: ' . $this->appSecret,
        ];

        // Injected transport (used in tests)
        if ($this->transport !== null) {
            $result = ($this->transport)($url, $data, $headers);
            return is_array($result) ? $result : null;
        }

        // Prefer cURL
        if (function_exists('curl_init')) {
            $raw = $this->rawRequestWithCurl($url, $data, $headers, $this->timeout);
        } else {
            // Fallback to file_get_contents
            $raw = $this->rawRequestWithStream($url, $data, $headers, $this->timeout);
        }

        return self::decodeResponse($raw);
    }

    /**
     * Decode a raw [status, body] pair into the {code, msg, data} envelope.
     * Returns null (handled as 'request_failed') on transport failure or when
     * the body is not a JSON object. A code:0 body on a non-2xx status is
     * never treated as success.
     *
     * @param array{0:int,1:string}|null $raw
     */
    private static function decodeResponse(?array $raw): ?array
    {
        if ($raw === null) {
            return null;
        }
        [$status, $body] = $raw;
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return null;
        }
        if ($status !== 0 && ($status < 200 || $status > 299) && ($decoded['code'] ?? -1) === 0) {
            $decoded['code'] = -1;
            if (!isset($decoded['msg']) || $decoded['msg'] === '') {
                $decoded['msg'] = 'http_' . $status;
            }
        }
        return $decoded;
    }

    /**
     * POST using cURL. Returns [httpStatus, body] or null on transport failure.
     *
     * @return array{0:int,1:string}|null
     */
    private function rawRequestWithCurl(string $url, array $data, array $headers, int $timeout): ?array
    {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($error || $response === false || $status === 0) {
            return null;
        }

        return [$status, (string)$response];
    }

    /**
     * POST using file_get_contents (fallback). Returns [httpStatus, body] or
     * null on transport failure. httpStatus is 0 if it cannot be determined.
     *
     * @return array{0:int,1:string}|null
     */
    private function rawRequestWithStream(string $url, array $data, array $headers, int $timeout): ?array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => json_encode($data),
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $http_response_header = [];
        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            return null;
        }

        $status = 0;
        if (isset($http_response_header[0])
            && preg_match('#^HTTP/\S+\s+(\d{3})#', (string)$http_response_header[0], $m)) {
            $status = (int)$m[1];
        }

        return [$status, $response];
    }
}
