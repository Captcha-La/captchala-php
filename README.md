# Captchala PHP SDK

Server-side SDK for validating Captcha tokens.

**English** | [简体中文](README_zh.md)

- [Website](https://captcha.la)
- [Documentation](https://docs.captcha.la/sdk/server-php)
- [Web SDK Documentation](https://docs.captcha.la/web-sdk)
- [Dashboard](https://dash.captcha.la)

## Installation

```bash
composer require captchala/captchala-php
```

## Quick Start

```php
<?php

use Captchala\Client;

// Create client
$client = new Client('your_app_key', 'your_app_secret');

// Validate token
$result = $client->validate($token);

if ($result->isValid()) {
    // Verification passed
    if ($result->isOffline()) {
        // Offline verification - may need additional risk control
    }
} else {
    // Verification failed
    echo $result->getError();
}
```

## API Reference

### `Client::__construct(string $appKey, string $appSecret, int $timeout = 5)`

Create a client instance.

- `$appKey` - App Key (from dashboard)
- `$appSecret` - App Secret (from dashboard)
- `$timeout` - Request timeout in seconds (default: 5)

### `Client::validate(string $token, bool $keepToken = false, ?string $clientIp = null): ValidateResult`

Validate a token.

- `$token` - The pass_token from frontend SDK
- `$keepToken` - If true, token won't be consumed (can be validated again)
- `$clientIp` - *(deprecated, ignored)* Kept for backward compatibility only.
  The IP is **no longer sent or used for the pass/fail decision**: across a
  CDN + dual-stack (IPv4/IPv6) setup the visitor solves the challenge over one
  address family and submits your form over another, so comparing them rejects
  legitimate users. This mirrors Turnstile / reCAPTCHA (where `remoteip` is an
  optional soft signal) and Geetest (which records the IP and returns it). The
  IP the platform observed at solve time is returned via
  `ValidateResult::getUserIp()` for your own logging / risk use.

```php
$result = $client->validate($token);
if ($result->isValid()) {
    // ... let the request through ...
    $solveIp = $result->getUserIp(); // informational only — do NOT gate on it
}
```

### `ValidateResult` Methods

| Method | Return Type | Description |
|--------|-------------|-------------|
| `isValid()` | bool | Whether validation passed |
| `isOffline()` | bool | Whether this was offline verification |
| `isClientOnly()` | bool | Whether this is a client-only token |
| `getError()` | ?string | Get error message |
| `getWarning()` | ?string | Get warning message |
| `getChallengeId()` | ?string | Get challenge ID |
| `getAction()` | ?string | Get business action |
| `getUid()` | ?string | User ID bound via `bind_uid` — verify the pass_token belongs to the expected user |
| `getUserIp()` | ?string | End-user IP recorded at solve time. **Informational only** — not used for pass/fail; do not gate on it. |
| `getCaptchaArgs()` | array | Solve-context echo (`platform`, `user_ip`, `referer`, `pkg`, `solved_at`, `risk_score`). All informational. |
| `toArray()` | array | Convert to array |

### Solve-context echo (`captcha_args`)

`getCaptchaArgs()` returns what the platform recorded **at solve time** — use it
for logging / your own risk scoring, never as a pass/fail gate:

```php
$args = $result->getCaptchaArgs();
// [
//   'platform'   => 'web',          // web / android / ios / flutter / windows / ...
//   'user_ip'    => '1.2.3.4',      // end-user IP at solve time
//   'referer'    => 'https://...',  // web: solve page URL (null on native)
//   'pkg'        => null,           // native: app package id (null on web)
//   'solved_at'  => 1750000000,     // unix seconds
//   'risk_score' => 12,             // 0-100, higher = riskier
// ]
```

### Verifying `bind_uid`

If you issued the `server_token` with `bind_uid = 'user_42'`, compare the
result against the expected user:

```php
$result = $client->validate($token);
if ($result->isValid() && $result->getUid() !== $expectedUserId) {
    // pass_token was issued for a different user — reject
}
```

### `Client::issueServerToken(string $action, ?string $bindingIp = null, ?int $ttl = null, ?int $maxUses = null, ?string $bindUid = null): IssueResult`

Mint a one-time `sct_` server token. Hand the returned token to the browser
SDK via the `serverToken` prop — single-use, action-scoped, optionally
IP/UID-bound.

- `$action` - Business scene (`login`, `register`, `payment`, …)
- `$bindingIp` - *(optional)* End-user IP; backend rejects token if a different IP redeems it
- `$ttl` - *(optional)* Lifetime in seconds; server enforces an upper bound (default 300)
- `$maxUses` - *(optional)* SDK retry budget; verification is still single-pass
- `$bindUid` - *(optional)* User ID; pair with `ValidateResult::getUid()` on verify

```php
$issue = $client->issueServerToken('login', $request->ip(), 300, 5, $user->id);
if (!$issue->isOk()) {
    return ['error' => $issue->getError()];   // rate_limit_exceeded, ...
}
return ['server_token' => $issue->getToken()];   // hand to browser
```

| `IssueResult` Method | Return | Description |
|---|---|---|
| `isOk()` | bool | Issuance succeeded |
| `getToken()` | ?string | The `sct_<hex>` server token |
| `getExpiresIn()` | ?int | TTL in seconds |
| `getIssuedAt()` | ?int | Unix timestamp (seconds) |
| `getError()` | ?string | Error code |
| `getMessage()` | ?string | Human-readable error message |

### `Client::moderationCheck(array $input, ?string $userId = null): ModerationResult`

Multi-modal content moderation. `$input` is a list of `{type, ...}` items in
OpenAI-compatible format — text and image_url can be mixed in one call.

```php
$result = $client->moderationCheck([
    ['type' => 'text', 'text' => $userComment],
    ['type' => 'image_url', 'image_url' => ['url' => $uploadedImageUrl]],
], $user->id);

if ($result->isFlagged() && $result->hasCategory('violence', 'csam')) {
    // hard block
}
```

### `Client::moderationText(string $text, ?string $userId = null): ModerationResult`

Convenience wrapper for plain-text moderation.

```php
$result = $client->moderationText('user comment here', $user->id);
```

| `ModerationResult` Method | Return | Description |
|---|---|---|
| `isOk()` | bool | Request succeeded (regardless of `flagged`) |
| `isFlagged()` | bool | Upstream model verdict |
| `hasCategory(...$names)` | bool | True if any named category tripped |
| `getCategories()` | array | Map of `category → bool`; categories vary by upstream model |
| `getContentType()` | ?string | `'text'` / `'image'` / `'mixed'` |
| `getRaw()` | array | Full upstream payload for advanced inspection |
| `getError()` | ?string | Error code |
| `getMessage()` | ?string | Human-readable error message |

## Token Types

| Prefix | Source | Security Level |
|--------|--------|----------------|
| `pt_` | Main API | High |
| `offline_` | Backup Service | Medium |
| `client_` | Client-only | Low (cannot verify server-side) |

## Complete Example

```php
<?php

use Captchala\Client;

// Validation in login/register scenarios
function handleLogin(array $data): bool
{
    $client = new Client(
        getenv('CAPTCHALA_APP_KEY'),
        getenv('CAPTCHALA_APP_SECRET')
    );

    $result = $client->validate($data['captcha_token']);

    if (!$result->isValid()) {
        throw new Exception('Captcha verification failed: ' . $result->getError());
    }

    // Additional risk control for offline verification
    if ($result->isOffline()) {
        // Log for monitoring
        error_log('Offline captcha verification: ' . json_encode($result->toArray()));

        // Optional: Restrict sensitive operations for client-only tokens
        if ($result->isClientOnly()) {
            // Add extra verification or limit sensitive operations
        }
    }

    // Continue with login logic...
    return true;
}
```

## Laravel Integration

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Captchala\Client;

class ValidateCaptcha
{
    private Client $captcha;

    public function __construct()
    {
        $this->captcha = new Client(
            config('services.captchala.key'),
            config('services.captchala.secret')
        );
    }

    public function handle($request, Closure $next)
    {
        $token = $request->input('captcha_token');

        if (!$token) {
            return response()->json(['error' => 'missing_captcha_token'], 400);
        }

        $result = $this->captcha->validate($token);

        if (!$result->isValid()) {
            return response()->json([
                'error' => 'captcha_failed',
                'message' => $result->getError(),
            ], 400);
        }

        // Store for later use
        $request->attributes->set('captcha_offline', $result->isOffline());
        $request->attributes->set('captcha_client_only', $result->isClientOnly());

        return $next($request);
    }
}
```

## Testing

```bash
# Install dependencies
composer install

# Run tests
composer test

# Integration tests (requires real credentials)
CAPTCHALA_APP_KEY=xxx CAPTCHALA_APP_SECRET=xxx composer test
```

## License

MIT

---

## CMS plugin helpers (`Captchala\Cms`)

Used by CaptchaLa's CMS plugins (WordPress, Joomla, Drupal, Magento, …) — most
integrators don't need these directly.

### Action constants

```php
use Captchala\Cms\Action;

$server = $client->issueServerToken(Action::LOGIN, $request->ip());
```

### Widget renderer

```php
use Captchala\Cms\Widget;
use Captchala\Cms\Action;

echo Widget::renderHtml($appKey, $serverToken, Action::LOGIN, [
    'product'      => 'bind',
    'lang'         => 'ja',
    'hidden_input' => true,   // also emits <input name="captchala_token">
]);
```

### Error standardizer

```php
use Captchala\Cms\Errors;

$result = $client->validate($_POST['captchala_token']);
if (!$result->isValid()) {
    show_form_error(Errors::standardize($result->getError()));
}
```
