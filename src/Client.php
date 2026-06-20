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
    public const PREFIX_CLIENT = 'client_';   // Client-only token

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

        // Client-only token (client_ prefix)
        // Cannot be verified server-side
        if (str_starts_with($token, self::PREFIX_CLIENT)) {
            return new ValidateResult(
                valid: true,
                offline: true,
                clientOnly: true,
                warning: 'Client-only token cannot be verified server-side'
            );
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

        // Build request body.
        //
        // $clientIp is intentionally NOT sent. The dashboard no longer uses an
        // integrator-supplied IP for the pass/fail decision: cross-domain +
        // dual-stack (IPv4/IPv6) means the same visitor solves the challenge
        // over one address family and submits the form over another, so a
        // solve-vs-submit IP comparison rejects legitimate users. This matches
        // Turnstile / reCAPTCHA (remoteip is an optional soft signal, never a
        // hard gate) and Geetest (records the IP server-side, returns it). The
        // IP the platform saw at solve time comes back as `user_ip` below
        // (ValidateResult::getUserIp()).
        //
        // The $clientIp parameter stays on the signature so existing callers
        // using validate($token, false, $ip) keep working unchanged.
        $body = [
            'pass_token' => $token,
            'keep_token' => $keepToken,
        ];

        $response = $this->request($apiUrl, $body);

        if ($response === null) {
            return new ValidateResult(false, $isOffline, false, 'request_failed');
        }

        // Parse response
        $code = $response['code'] ?? -1;
        $data = $response['data'] ?? [];

        if ($code === 0 && ($data['valid'] ?? $data['ok'] ?? false)) {
            return new ValidateResult(
                valid: true,
                offline: $isOffline,
                clientOnly: false,
                challengeId: $data['challenge_id'] ?? null,
                action: $data['action'] ?? null,
                uid: isset($data['uid']) && is_string($data['uid']) ? $data['uid'] : null,
                // user_ip lives inside captcha_args (Geetest-style); accept a
                // top-level user_ip too for forward/backward tolerance.
                userIp: self::extractUserIp($data),
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
                error: $data['error'] ?? null,
                degraded: true,
                degradedReason: isset($data['reason']) && is_string($data['reason']) ? $data['reason'] : 'quota_exhausted',
            );
        }

        $error = $data['error'] ?? $response['msg'] ?? 'unknown_error';
        return new ValidateResult(false, $isOffline, false, $error);
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
        $data = $response['data'] ?? [];
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
            error: $response['error'] ?? $data['error'] ?? 'unknown_error',
            message: $response['msg'] ?? $response['message'] ?? null,
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
        $data = $response['data'] ?? [];
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
            error: $response['error'] ?? $data['error'] ?? 'unknown_error',
            message: $response['msg'] ?? $response['message'] ?? null,
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
            return ($this->transport)($url, $data, $headers);
        }

        // Prefer cURL
        if (function_exists('curl_init')) {
            return $this->requestWithCurl($url, $data, $headers);
        }

        // Fallback to file_get_contents
        return $this->requestWithStream($url, $data, $headers);
    }

    /**
     * Make request using cURL
     */
    private function requestWithCurl(string $url, array $data, array $headers): ?array
    {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error || $response === false) {
            return null;
        }

        return json_decode($response, true);
    }

    /**
     * Make request using file_get_contents (fallback)
     */
    private function requestWithStream(string $url, array $data, array $headers): ?array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => json_encode($data),
                'timeout' => $this->timeout,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            return null;
        }

        return json_decode($response, true);
    }
}
