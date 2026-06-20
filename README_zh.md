# Captchala PHP SDK

服务端验证 Captcha Token 的 PHP SDK。

[English](README.md) | **简体中文**

- [官网](https://captcha.la)
- [文档](https://docs.captcha.la/zh-CN/sdk/server-php)
- [Dashboard](https://dash.captcha.la)

## 安装

```bash
composer require captchala/captchala-php
```

## 快速开始

```php
<?php

use Captchala\Client;

// 创建客户端
$client = new Client('your_app_key', 'your_app_secret');

// 验证 Token
$result = $client->validate($token);

if ($result->isValid()) {
    // 验证通过
    if ($result->isOffline()) {
        // 离线验证 - 可能需要额外风控
    }
} else {
    // 验证失败
    echo $result->getError();
}
```

## API

### `Client::__construct(string $appKey, string $appSecret, int $timeout = 5)`

创建客户端实例。

- `$appKey` - 应用 Key（在控制台获取）
- `$appSecret` - 应用 Secret（在控制台获取）
- `$timeout` - 请求超时时间（秒），默认 5 秒

### `Client::validate(string $token, bool $keepToken = false, ?string $clientIp = null): ValidateResult`

验证 Token。

- `$token` - 前端 SDK 返回的 pass_token
- `$keepToken` - 是否保留 Token 不消费（可重复验证），默认 false
- `$clientIp` - *（已废弃，被忽略）* 仅为向后兼容保留。该 IP **不再发送、也不参与
  pass/fail 判定**：跨域 CDN + 双栈(IPv4/IPv6)下,用户解 challenge 走一个地址族、
  提交表单走另一个族,硬比对会误杀正常用户。这与 Turnstile / reCAPTCHA(remoteip 为
  可选软信号)及 Geetest(记录 IP 并返回)一致。平台在解题时观测到的 IP 通过
  `ValidateResult::getUserIp()` 返回,供你做日志/风控。

### `ValidateResult` 方法

| 方法 | 返回类型 | 说明 |
|------|---------|------|
| `isValid()` | bool | 验证是否通过 |
| `isOffline()` | bool | 是否为离线验证 |
| `isClientOnly()` | bool | 是否为纯客户端 Token |
| `getError()` | ?string | 获取错误信息 |
| `getWarning()` | ?string | 获取警告信息 |
| `getChallengeId()` | ?string | 获取挑战 ID |
| `getAction()` | ?string | 获取业务动作 |
| `getUid()` | ?string | server_token 签发时绑定的 user ID, 用于核对 pass_token 是否给预期用户的 |
| `getUserIp()` | ?string | 解题时记录的终端用户 IP。**仅供参考**,不参与 pass/fail,不要用它做门禁 |
| `getCaptchaArgs()` | array | 解题上下文回显(`platform`/`user_ip`/`referer`/`pkg`/`solved_at`/`risk_score`),全部仅供参考 |
| `toArray()` | array | 转换为数组 |

### 解题上下文回显(`captcha_args`)

`getCaptchaArgs()` 返回平台在**解题当时**记录的上下文,用于日志 / 你自己的二次风控,**不要拿来做 pass/fail 门禁**:

```php
$args = $result->getCaptchaArgs();
// [
//   'platform'   => 'web',          // web / android / ios / flutter / windows / ...
//   'user_ip'    => '1.2.3.4',      // 解题时的用户 IP
//   'referer'    => 'https://...',  // web: 解题页面 URL(native 为 null)
//   'pkg'        => null,           // native: app 包名(web 为 null)
//   'solved_at'  => 1750000000,     // unix 秒
//   'risk_score' => 12,             // 0-100,越高越可疑
// ]
```

### 校验 `bind_uid`

如果签发 `server_token` 时带了 `bind_uid = 'user_42'`, 校验时核对：

```php
$result = $client->validate($token);
if ($result->isValid() && $result->getUid() !== $expectedUserId) {
    // pass_token 是给别的用户签的 — 拒绝
}
```

### `Client::issueServerToken(string $action, ?string $bindingIp = null, ?int $ttl = null, ?int $maxUses = null, ?string $bindUid = null): IssueResult`

签发一次性 `sct_` server token。把返回的 token 下发给前端, 浏览器作为
`serverToken` prop 传给组件 — 单次消费, 绑定 action, 可选绑定 IP / UID。

- `$action` - 业务场景 (`login`、`register`、`payment`...)
- `$bindingIp` - *可选* 终端用户 IP; 不同 IP 来兑换会被后端拒绝
- `$ttl` - *可选* 有效期（秒）; 服务端有上限, 默认 300
- `$maxUses` - *可选* SDK 重试预算; 校验仍是单次消费
- `$bindUid` - *可选* user ID; 配合校验侧 `ValidateResult::getUid()` 核对

```php
$issue = $client->issueServerToken('login', $request->ip(), 300, 5, $user->id);
if (!$issue->isOk()) {
    return ['error' => $issue->getError()];   // rate_limit_exceeded ...
}
return ['server_token' => $issue->getToken()];   // 下发给浏览器
```

| `IssueResult` 方法 | 返回 | 说明 |
|---|---|---|
| `isOk()` | bool | 是否签发成功 |
| `getToken()` | ?string | `sct_<hex>` server token |
| `getExpiresIn()` | ?int | 有效期（秒） |
| `getIssuedAt()` | ?int | Unix 时间戳（秒） |
| `getError()` | ?string | 错误码 |
| `getMessage()` | ?string | 错误描述 |

### `Client::moderationCheck(array $input, ?string $userId = null): ModerationResult`

多模态内容审核。`$input` 是 `{type, ...}` 数组, OpenAI 兼容格式 — 文本和 image_url
可在一次请求里混合。

```php
$result = $client->moderationCheck([
    ['type' => 'text', 'text' => $userComment],
    ['type' => 'image_url', 'image_url' => ['url' => $uploadedImageUrl]],
], $user->id);

if ($result->isFlagged() && $result->hasCategory('violence', 'csam')) {
    // 硬阻断
}
```

### `Client::moderationText(string $text, ?string $userId = null): ModerationResult`

纯文本审核快捷方式。

```php
$result = $client->moderationText('user comment here', $user->id);
```

| `ModerationResult` 方法 | 返回 | 说明 |
|---|---|---|
| `isOk()` | bool | 请求成功（无论 `flagged`） |
| `isFlagged()` | bool | 上游模型判定违规 |
| `hasCategory(...$names)` | bool | 任一指定 category 命中即 true |
| `getCategories()` | array | `category → bool` 映射; category 由上游模型决定 |
| `getContentType()` | ?string | `'text'` / `'image'` / `'mixed'` |
| `getRaw()` | array | 完整上游 payload |
| `getError()` | ?string | 错误码 |
| `getMessage()` | ?string | 错误描述 |

## Token 类型

| 前缀 | 来源 | 安全级别 |
|------|------|---------|
| `pt_` | 主服务 | 高 |
| `offline_` | 备用服务 | 中 |
| `client_` | 纯客户端 | 低（无法服务端验证） |

## 完整示例

```php
<?php

use Captchala\Client;

// 在登录/注册等场景验证
function handleLogin(array $data): bool
{
    $client = new Client(
        getenv('CAPTCHALA_APP_KEY'),
        getenv('CAPTCHALA_APP_SECRET')
    );

    $result = $client->validate($data['captcha_token']);

    if (!$result->isValid()) {
        throw new Exception('验证码验证失败: ' . $result->getError());
    }

    // 对离线验证增加额外风控
    if ($result->isOffline()) {
        // 记录日志
        error_log('离线验证通过: ' . json_encode($result->toArray()));

        // 可选：对纯客户端 Token 增加限制
        if ($result->isClientOnly()) {
            // 限制敏感操作或增加二次验证
        }
    }

    // 继续处理登录逻辑...
    return true;
}
```

## Laravel 集成

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

        // 存储结果供后续使用
        $request->attributes->set('captcha_offline', $result->isOffline());
        $request->attributes->set('captcha_client_only', $result->isClientOnly());

        return $next($request);
    }
}
```

## 测试

```bash
# 安装依赖
composer install

# 运行测试
composer test

# 集成测试（需要真实凭证）
CAPTCHALA_APP_KEY=xxx CAPTCHALA_APP_SECRET=xxx composer test
```

## License

MIT
