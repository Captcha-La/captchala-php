# Captchala PHP SDK

[English](https://github.com/Captcha-La/captchala-php/blob/main/README.md) · [简体中文](https://github.com/Captcha-La/captchala-php/blob/main/README_zh.md) · **日本語** · [한국어](https://github.com/Captcha-La/captchala-php/blob/main/README.ko.md) · [Español](https://github.com/Captcha-La/captchala-php/blob/main/README.es.md) · [Português (BR)](https://github.com/Captcha-La/captchala-php/blob/main/README.pt-BR.md) · [Deutsch](https://github.com/Captcha-La/captchala-php/blob/main/README.de.md)

Captcha トークンをサーバー側で検証するための SDK です。

- [Web サイト](https://captcha.la)
- [ドキュメント](https://docs.captcha.la/sdk/server-php)
- [Web SDK ドキュメント](https://docs.captcha.la/web-sdk)
- [ダッシュボード](https://dash.captcha.la)

## インストール

```bash
composer require captchala/captchala-php
```

## クイックスタート

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

## API リファレンス

### `Client::__construct(string $appKey, string $appSecret, int $timeout = 5)`

クライアントインスタンスを作成します。

- `$appKey` - App Key（ダッシュボードで取得）
- `$appSecret` - App Secret（ダッシュボードで取得）
- `$timeout` - リクエストのタイムアウト秒数（デフォルト: 5）

### `Client::validate(string $token, bool $keepToken = false, ?string $clientIp = null): ValidateResult`

トークンを検証します。

- `$token` - フロントエンド SDK から受け取った pass_token
- `$keepToken` - true の場合、トークンは消費されません（再度検証できます）
- `$clientIp` - *（任意、推奨）* 受信したリクエストから取得したエンドユーザーの IP。
  追加のリスクチェックに使われます。省略しても問題ありません。

```php
$result = $client->validate($token, false, $request->ip());
if ($result->isValid()) {
    // ... let the request through ...
}
```

### `ValidateResult` のメソッド

| メソッド | 戻り値の型 | 説明 |
|--------|-------------|-------------|
| `isValid()` | bool | 検証に成功したかどうか |
| `isOffline()` | bool | オフライン検証だったかどうか |
| `isClientOnly()` | bool | クライアント専用トークンかどうか |
| `getError()` | ?string | エラーメッセージを取得 |
| `getWarning()` | ?string | 警告メッセージを取得 |
| `getChallengeId()` | ?string | チャレンジ ID を取得 |
| `getAction()` | ?string | ビジネスアクションを取得 |
| `getUid()` | ?string | `bind_uid` でバインドされたユーザー ID。pass_token が想定どおりのユーザーのものか確認できます |
| `getUserIp()` | ?string | 解答時に記録されたエンドユーザー IP（参考情報）。 |
| `getCaptchaArgs()` | array | 解答時コンテキストのエコー（`platform`、`user_ip`、`referer`、`pkg`、`solved_at`、`risk_score`）。すべて参考情報です。 |
| `toArray()` | array | 配列に変換 |

### 解答時コンテキストのエコー（`captcha_args`）

`getCaptchaArgs()` は、プラットフォームが**解答時点**で記録した内容を返します。
ログ記録や独自のリスクスコアリングに使い、合否の判定には絶対に使わないでください。

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

### `bind_uid` の確認

`bind_uid = 'user_42'` を指定して `server_token` を発行した場合は、
結果を想定ユーザーと照合してください。

```php
$result = $client->validate($token);
if ($result->isValid() && $result->getUid() !== $expectedUserId) {
    // pass_token was issued for a different user — reject
}
```

### `Client::issueServerToken(string $action, ?string $bindingIp = null, ?int $ttl = null, ?int $maxUses = null, ?string $bindUid = null): IssueResult`

使い捨ての `sct_` server token を発行します。返されたトークンはブラウザ SDK の
`serverToken` プロパティに渡してください。1 回限り有効で、アクション単位にスコープされ、
必要に応じて IP / UID にバインドできます。

- `$action` - ビジネスシーン（`login`、`register`、`payment` など）
- `$bindingIp` - *（任意）* エンドユーザーの IP。別の IP から使用された場合、バックエンドはトークンを拒否します
- `$ttl` - *（任意）* 有効期間（秒）。サーバー側で上限が適用されます（デフォルト 300）
- `$maxUses` - *（任意）* SDK のリトライ回数の上限。検証自体は 1 回限りです
- `$bindUid` - *（任意）* ユーザー ID。検証時に `ValidateResult::getUid()` と照合します

```php
$issue = $client->issueServerToken('login', $request->ip(), 300, 5, $user->id);
if (!$issue->isOk()) {
    return ['error' => $issue->getError()];   // rate_limit_exceeded, ...
}
return ['server_token' => $issue->getToken()];   // hand to browser
```

| `IssueResult` のメソッド | 戻り値 | 説明 |
|---|---|---|
| `isOk()` | bool | 発行に成功した |
| `getToken()` | ?string | `sct_<hex>` 形式の server token |
| `getExpiresIn()` | ?int | TTL（秒） |
| `getIssuedAt()` | ?int | Unix タイムスタンプ（秒） |
| `getError()` | ?string | エラーコード |
| `getMessage()` | ?string | 人が読めるエラーメッセージ |

### `Client::moderationCheck(array $input, ?string $userId = null): ModerationResult`

マルチモーダルなコンテンツモデレーションです。`$input` は OpenAI 互換形式の
`{type, ...}` 項目のリストで、1 回の呼び出しに text と image_url を混在させられます。

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

プレーンテキストのモデレーション用の便利なラッパーです。

```php
$result = $client->moderationText('user comment here', $user->id);
```

| `ModerationResult` のメソッド | 戻り値 | 説明 |
|---|---|---|
| `isOk()` | bool | リクエストが成功した（`flagged` の値とは無関係） |
| `isFlagged()` | bool | 上流モデルの判定 |
| `hasCategory(...$names)` | bool | 指定したカテゴリのいずれかに該当すれば true |
| `getCategories()` | array | `category → bool` のマップ。カテゴリは上流モデルによって異なります |
| `getContentType()` | ?string | `'text'` / `'image'` / `'mixed'` |
| `getRaw()` | array | 詳細な調査用の上流ペイロード全体 |
| `getError()` | ?string | エラーコード |
| `getMessage()` | ?string | 人が読めるエラーメッセージ |

## トークンの種類

| プレフィックス | 発行元 | セキュリティレベル |
|--------|--------|----------------|
| `pt_` | メイン API | 高 |
| `offline_` | バックアップサービス | 中 |
| `dg_` | メイン API、縮退モード（クォータ超過時など） | `isValid()` は常に `false`。`isDegraded()` を確認して自分で判断してください |
| `client_` | クライアント専用プレースホルダー（ネイティブ SDK） | なし（下記参照） |
| `co_` | クライアント専用プレースホルダー（Web SDK） | なし（下記参照） |

### クライアント専用トークン（`client_` / `co_`）

これらのプレースホルダーは、メイン API とバックアップサービスの**両方**に到達できなかった
場合にのみ、デバイス上で生成されます。署名もサーバー側の記録もありません。
v1.2.5 以降、SDK はこれらを無条件には受け入れません。

1. まずメイン API を確認します（タイムアウト 2 秒）。何らかの HTTP 応答があれば障害は
   発生していないと判断し、`isValid()=false`、`isClientOnly()=true`、
   `getError()='client_only_rejected_api_reachable'` となります。
2. そうでなければバックアップ API を確認します（タイムアウト 2 秒）。HTTP 応答があれば同様に
   拒否します（デバイスは `offline_` トークンを受け取っているはずだからです）。
3. 両方とも到達不能（ネットワークエラー / タイムアウト）の場合に限り、`isValid()=true`、
   `isClientOnly()=true`、`isOffline()=true` となります。

確認結果はキャッシュされます。「到達可能」は 30 秒、「到達不能」は 5 秒です。そのため障害時でも、
すべてのリクエストが 2 回のタイムアウトを待つことはありません。APCu 拡張が有効な場合、
キャッシュはすべての PHP-FPM ワーカーで共有され、あるエンドポイントを同時に確認するのは
1 ワーカーだけです。APCu がない場合はプロセスごとのキャッシュになります。
これらのプレフィックスを判定するには `Client::isClientOnlyToken($token)` を使います。

機密性の高いアクション（ログイン、決済）では、障害時であってもクライアント専用トークンを
拒否できます。

```php
$client->setRejectClientOnly(true);
// client_ / co_ tokens -> isValid()=false, getError()='client_only_rejected_by_policy'
```

## 完全な例

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

## Laravel との統合

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

## テスト

```bash
# Install dependencies
composer install

# Run tests
composer test

# Integration tests (requires real credentials)
CAPTCHALA_APP_KEY=xxx CAPTCHALA_APP_SECRET=xxx composer test
```

## ライセンス

MIT

---

## CMS プラグイン用ヘルパー（`Captchala\Cms`）

CaptchaLa の CMS プラグイン（WordPress、Joomla、Drupal、Magento など）で使われています。
ほとんどの導入者はこれらを直接使う必要はありません。

### アクション定数

```php
use Captchala\Cms\Action;

$server = $client->issueServerToken(Action::LOGIN, $request->ip());
```

### ウィジェットレンダラー

```php
use Captchala\Cms\Widget;
use Captchala\Cms\Action;

echo Widget::renderHtml($appKey, $serverToken, Action::LOGIN, [
    'product'      => 'bind',
    'lang'         => 'ja',
    'hidden_input' => true,   // also emits <input name="captchala_token">
]);
```

### エラーの標準化

```php
use Captchala\Cms\Errors;

$result = $client->validate($_POST['captchala_token']);
if (!$result->isValid()) {
    show_form_error(Errors::standardize($result->getError()));
}
```
