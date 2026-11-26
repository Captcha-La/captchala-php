# Captchala PHP SDK

[English](https://github.com/Captcha-La/captchala-php/blob/main/README.md) · [简体中文](https://github.com/Captcha-La/captchala-php/blob/main/README_zh.md) · [日本語](https://github.com/Captcha-La/captchala-php/blob/main/README.ja.md) · **한국어** · [Español](https://github.com/Captcha-La/captchala-php/blob/main/README.es.md) · [Português (BR)](https://github.com/Captcha-La/captchala-php/blob/main/README.pt-BR.md) · [Deutsch](https://github.com/Captcha-La/captchala-php/blob/main/README.de.md)

Captcha 토큰을 서버 측에서 검증하기 위한 SDK입니다.

- [웹사이트](https://captcha.la)
- [문서](https://docs.captcha.la/sdk/server-php)
- [Web SDK 문서](https://docs.captcha.la/web-sdk)
- [대시보드](https://dash.captcha.la)

## 설치

```bash
composer require captchala/captchala-php
```

## 빠른 시작

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

## API 레퍼런스

### `Client::__construct(string $appKey, string $appSecret, int $timeout = 5)`

클라이언트 인스턴스를 생성합니다.

- `$appKey` - App Key (대시보드에서 발급)
- `$appSecret` - App Secret (대시보드에서 발급)
- `$timeout` - 요청 타임아웃(초, 기본값: 5)

### `Client::validate(string $token, bool $keepToken = false, ?string $clientIp = null): ValidateResult`

토큰을 검증합니다.

- `$token` - 프런트엔드 SDK에서 받은 pass_token
- `$keepToken` - true이면 토큰이 소비되지 않습니다(다시 검증할 수 있음)
- `$clientIp` - *(선택, 권장)* 들어온 요청에서 얻은 최종 사용자의 IP입니다.
  추가 위험 검사에 사용됩니다. 생략해도 됩니다.

```php
$result = $client->validate($token, false, $request->ip());
if ($result->isValid()) {
    // ... let the request through ...
}
```

### `ValidateResult` 메서드

| 메서드 | 반환 타입 | 설명 |
|--------|-------------|-------------|
| `isValid()` | bool | 검증 통과 여부 |
| `isOffline()` | bool | 오프라인 검증이었는지 여부 |
| `isClientOnly()` | bool | 클라이언트 전용 토큰인지 여부 |
| `getError()` | ?string | 오류 메시지 반환 |
| `getWarning()` | ?string | 경고 메시지 반환 |
| `getChallengeId()` | ?string | 챌린지 ID 반환 |
| `getAction()` | ?string | 비즈니스 액션 반환 |
| `getUid()` | ?string | `bind_uid`로 바인딩된 사용자 ID — pass_token이 예상한 사용자의 것인지 확인하세요 |
| `getUserIp()` | ?string | 풀이 시점에 기록된 최종 사용자 IP(참고용). |
| `getCaptchaArgs()` | array | 풀이 컨텍스트 에코(`platform`, `user_ip`, `referer`, `pkg`, `solved_at`, `risk_score`). 모두 참고용입니다. |
| `toArray()` | array | 배열로 변환 |

### 풀이 컨텍스트 에코 (`captcha_args`)

`getCaptchaArgs()`는 플랫폼이 **풀이 시점에** 기록한 내용을 반환합니다. 로깅이나
자체 위험 점수 계산에 사용하고, 통과/실패 판정 기준으로는 절대 사용하지 마세요.

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

### `bind_uid` 확인

`bind_uid = 'user_42'`로 `server_token`을 발급했다면, 결과를 예상 사용자와
비교하세요.

```php
$result = $client->validate($token);
if ($result->isValid() && $result->getUid() !== $expectedUserId) {
    // pass_token was issued for a different user — reject
}
```

### `Client::issueServerToken(string $action, ?string $bindingIp = null, ?int $ttl = null, ?int $maxUses = null, ?string $bindUid = null): IssueResult`

일회용 `sct_` server token을 발급합니다. 반환된 토큰은 브라우저 SDK의 `serverToken`
prop으로 전달하세요. 한 번만 사용할 수 있고, 액션 단위로 범위가 지정되며, 선택적으로
IP/UID에 바인딩할 수 있습니다.

- `$action` - 비즈니스 시나리오(`login`, `register`, `payment` 등)
- `$bindingIp` - *(선택)* 최종 사용자 IP. 다른 IP에서 사용하면 백엔드가 토큰을 거부합니다
- `$ttl` - *(선택)* 유효 기간(초). 서버에서 상한을 적용합니다(기본값 300)
- `$maxUses` - *(선택)* SDK 재시도 한도. 검증 자체는 여전히 한 번만 통과합니다
- `$bindUid` - *(선택)* 사용자 ID. 검증 시 `ValidateResult::getUid()`와 비교합니다

```php
$issue = $client->issueServerToken('login', $request->ip(), 300, 5, $user->id);
if (!$issue->isOk()) {
    return ['error' => $issue->getError()];   // rate_limit_exceeded, ...
}
return ['server_token' => $issue->getToken()];   // hand to browser
```

| `IssueResult` 메서드 | 반환값 | 설명 |
|---|---|---|
| `isOk()` | bool | 발급 성공 여부 |
| `getToken()` | ?string | `sct_<hex>` 형식의 server token |
| `getExpiresIn()` | ?int | TTL(초) |
| `getIssuedAt()` | ?int | Unix 타임스탬프(초) |
| `getError()` | ?string | 오류 코드 |
| `getMessage()` | ?string | 사람이 읽을 수 있는 오류 메시지 |

### `Client::moderationCheck(array $input, ?string $userId = null): ModerationResult`

멀티모달 콘텐츠 모더레이션입니다. `$input`은 OpenAI 호환 형식의 `{type, ...}` 항목
목록이며, 한 번의 호출에서 text와 image_url을 섞어 보낼 수 있습니다.

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

일반 텍스트 모더레이션을 위한 편의 래퍼입니다.

```php
$result = $client->moderationText('user comment here', $user->id);
```

| `ModerationResult` 메서드 | 반환값 | 설명 |
|---|---|---|
| `isOk()` | bool | 요청 성공 여부(`flagged` 값과 무관) |
| `isFlagged()` | bool | 업스트림 모델의 판정 |
| `hasCategory(...$names)` | bool | 지정한 카테고리 중 하나라도 해당하면 true |
| `getCategories()` | array | `category → bool` 맵. 카테고리는 업스트림 모델에 따라 다릅니다 |
| `getContentType()` | ?string | `'text'` / `'image'` / `'mixed'` |
| `getRaw()` | array | 심층 분석용 업스트림 전체 페이로드 |
| `getError()` | ?string | 오류 코드 |
| `getMessage()` | ?string | 사람이 읽을 수 있는 오류 메시지 |

## 토큰 유형

| 접두사 | 출처 | 보안 수준 |
|--------|--------|----------------|
| `pt_` | 메인 API | 높음 |
| `offline_` | 백업 서비스 | 중간 |
| `dg_` | 메인 API, 성능 저하 모드(예: 할당량 소진) | `isValid()`는 항상 `false`입니다. `isDegraded()`를 확인하고 직접 판단하세요 |
| `client_` | 클라이언트 전용 플레이스홀더(네이티브 SDK) | 없음 — 아래 참조 |
| `co_` | 클라이언트 전용 플레이스홀더(Web SDK) | 없음 — 아래 참조 |

### 클라이언트 전용 토큰 (`client_` / `co_`)

이 플레이스홀더는 메인 API와 백업 서비스 **모두**에 연결할 수 없을 때에만 기기에서
생성됩니다. 서명도 없고 서버 기록도 없습니다. v1.2.5부터 SDK는 이를 무조건 받아들이지
않습니다.

1. 먼저 메인 API를 확인합니다(타임아웃 2초). HTTP 응답이 하나라도 있으면 장애가 아니라는
   뜻이므로 `isValid()=false`, `isClientOnly()=true`,
   `getError()='client_only_rejected_api_reachable'`이 됩니다.
2. 그렇지 않으면 백업 API를 확인합니다(타임아웃 2초). HTTP 응답이 있으면 같은 방식으로
   거부합니다(기기는 `offline_` 토큰을 받았어야 하기 때문입니다).
3. 둘 다 연결할 수 없는 경우(네트워크 오류 / 타임아웃)에만 `isValid()=true`,
   `isClientOnly()=true`, `isOffline()=true`가 됩니다.

확인 결과는 캐시됩니다. "연결 가능"은 30초, "연결 불가"는 5초 동안 유지되므로 장애 중에도
모든 요청이 두 번의 타임아웃을 기다리지 않습니다. APCu 확장이 활성화되어 있으면 캐시를 모든
PHP-FPM 워커가 공유하며, 특정 엔드포인트는 한 번에 하나의 워커만 확인합니다. APCu가 없으면
프로세스별로 캐시합니다. 이 접두사를 감지하려면 `Client::isClientOnlyToken($token)`을
사용하세요.

민감한 액션(로그인, 결제)에서는 장애 중에도 클라이언트 전용 토큰을 거부할 수 있습니다.

```php
$client->setRejectClientOnly(true);
// client_ / co_ tokens -> isValid()=false, getError()='client_only_rejected_by_policy'
```

## 전체 예제

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

## Laravel 통합

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

## 테스트

```bash
# Install dependencies
composer install

# Run tests
composer test

# Integration tests (requires real credentials)
CAPTCHALA_APP_KEY=xxx CAPTCHALA_APP_SECRET=xxx composer test
```

## 라이선스

MIT

---

## CMS 플러그인 헬퍼 (`Captchala\Cms`)

CaptchaLa의 CMS 플러그인(WordPress, Joomla, Drupal, Magento 등)에서 사용합니다.
대부분의 통합 개발자는 이를 직접 사용할 필요가 없습니다.

### 액션 상수

```php
use Captchala\Cms\Action;

$server = $client->issueServerToken(Action::LOGIN, $request->ip());
```

### 위젯 렌더러

```php
use Captchala\Cms\Widget;
use Captchala\Cms\Action;

echo Widget::renderHtml($appKey, $serverToken, Action::LOGIN, [
    'product'      => 'bind',
    'lang'         => 'ja',
    'hidden_input' => true,   // also emits <input name="captchala_token">
]);
```

### 오류 표준화

```php
use Captchala\Cms\Errors;

$result = $client->validate($_POST['captchala_token']);
if (!$result->isValid()) {
    show_form_error(Errors::standardize($result->getError()));
}
```
