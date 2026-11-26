# Captchala PHP SDK

[English](https://github.com/Captcha-La/captchala-php/blob/main/README.md) · [简体中文](https://github.com/Captcha-La/captchala-php/blob/main/README_zh.md) · [日本語](https://github.com/Captcha-La/captchala-php/blob/main/README.ja.md) · [한국어](https://github.com/Captcha-La/captchala-php/blob/main/README.ko.md) · [Español](https://github.com/Captcha-La/captchala-php/blob/main/README.es.md) · [Português (BR)](https://github.com/Captcha-La/captchala-php/blob/main/README.pt-BR.md) · **Deutsch**

Serverseitiges SDK zur Validierung von Captcha-Tokens.

- [Website](https://captcha.la)
- [Dokumentation](https://docs.captcha.la/sdk/server-php)
- [Web-SDK-Dokumentation](https://docs.captcha.la/web-sdk)
- [Dashboard](https://dash.captcha.la)

## Installation

```bash
composer require captchala/captchala-php
```

## Schnellstart

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

## API-Referenz

### `Client::__construct(string $appKey, string $appSecret, int $timeout = 5)`

Erstellt eine Client-Instanz.

- `$appKey` - App Key (aus dem Dashboard)
- `$appSecret` - App Secret (aus dem Dashboard)
- `$timeout` - Timeout der Anfrage in Sekunden (Standard: 5)

### `Client::validate(string $token, bool $keepToken = false, ?string $clientIp = null): ValidateResult`

Validiert ein Token.

- `$token` - Das pass_token aus dem Frontend-SDK
- `$keepToken` - Bei true wird das Token nicht verbraucht (es kann erneut validiert werden)
- `$clientIp` - *(optional, empfohlen)* Die IP des Endnutzers aus Ihrer eingehenden
  Anfrage. Wird für zusätzliche Risikoprüfungen verwendet. Kann problemlos weggelassen werden.

```php
$result = $client->validate($token, false, $request->ip());
if ($result->isValid()) {
    // ... let the request through ...
}
```

### Methoden von `ValidateResult`

| Methode | Rückgabetyp | Beschreibung |
|--------|-------------|-------------|
| `isValid()` | bool | Ob die Validierung erfolgreich war |
| `isOffline()` | bool | Ob es eine Offline-Verifizierung war |
| `isClientOnly()` | bool | Ob es sich um ein reines Client-Token handelt |
| `getError()` | ?string | Liefert die Fehlermeldung |
| `getWarning()` | ?string | Liefert die Warnmeldung |
| `getChallengeId()` | ?string | Liefert die Challenge-ID |
| `getAction()` | ?string | Liefert die Business-Aktion |
| `getUid()` | ?string | Über `bind_uid` gebundene Benutzer-ID — prüfen Sie, ob das pass_token zum erwarteten Benutzer gehört |
| `getUserIp()` | ?string | Beim Lösen erfasste IP des Endnutzers (nur informativ). |
| `getCaptchaArgs()` | array | Echo des Lösungskontexts (`platform`, `user_ip`, `referer`, `pkg`, `solved_at`, `risk_score`). Alles nur informativ. |
| `toArray()` | array | In ein Array umwandeln |

### Echo des Lösungskontexts (`captcha_args`)

`getCaptchaArgs()` liefert, was die Plattform **zum Zeitpunkt des Lösens** erfasst hat. Nutzen Sie
es für Logging oder Ihre eigene Risikobewertung, aber niemals als Kriterium für Bestehen/Nichtbestehen:

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

### `bind_uid` prüfen

Wenn Sie das `server_token` mit `bind_uid = 'user_42'` ausgestellt haben, vergleichen Sie das
Ergebnis mit dem erwarteten Benutzer:

```php
$result = $client->validate($token);
if ($result->isValid() && $result->getUid() !== $expectedUserId) {
    // pass_token was issued for a different user — reject
}
```

### `Client::issueServerToken(string $action, ?string $bindingIp = null, ?int $ttl = null, ?int $maxUses = null, ?string $bindUid = null): IssueResult`

Stellt ein einmalig nutzbares `sct_`-server token aus. Übergeben Sie das zurückgegebene Token über
die Prop `serverToken` an das Browser-SDK: einmalig verwendbar, auf eine Aktion beschränkt und
optional an IP/UID gebunden.

- `$action` - Business-Szenario (`login`, `register`, `payment`, …)
- `$bindingIp` - *(optional)* IP des Endnutzers; das Backend lehnt das Token ab, wenn eine andere IP es einlöst
- `$ttl` - *(optional)* Gültigkeitsdauer in Sekunden; der Server erzwingt eine Obergrenze (Standard 300)
- `$maxUses` - *(optional)* Wiederholungsbudget des SDK; die Verifizierung bleibt einmalig
- `$bindUid` - *(optional)* Benutzer-ID; beim Verifizieren mit `ValidateResult::getUid()` abgleichen

```php
$issue = $client->issueServerToken('login', $request->ip(), 300, 5, $user->id);
if (!$issue->isOk()) {
    return ['error' => $issue->getError()];   // rate_limit_exceeded, ...
}
return ['server_token' => $issue->getToken()];   // hand to browser
```

| Methode von `IssueResult` | Rückgabe | Beschreibung |
|---|---|---|
| `isOk()` | bool | Ausstellung erfolgreich |
| `getToken()` | ?string | Das `sct_<hex>`-server token |
| `getExpiresIn()` | ?int | TTL in Sekunden |
| `getIssuedAt()` | ?int | Unix-Zeitstempel (Sekunden) |
| `getError()` | ?string | Fehlercode |
| `getMessage()` | ?string | Lesbare Fehlermeldung |

### `Client::moderationCheck(array $input, ?string $userId = null): ModerationResult`

Multimodale Inhaltsmoderation. `$input` ist eine Liste von `{type, ...}`-Einträgen im
OpenAI-kompatiblen Format — text und image_url lassen sich in einem Aufruf mischen.

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

Komfort-Wrapper für die Moderation von reinem Text.

```php
$result = $client->moderationText('user comment here', $user->id);
```

| Methode von `ModerationResult` | Rückgabe | Beschreibung |
|---|---|---|
| `isOk()` | bool | Anfrage erfolgreich (unabhängig von `flagged`) |
| `isFlagged()` | bool | Urteil des Upstream-Modells |
| `hasCategory(...$names)` | bool | True, wenn eine der genannten Kategorien ausgelöst wurde |
| `getCategories()` | array | Map `category → bool`; die Kategorien hängen vom Upstream-Modell ab |
| `getContentType()` | ?string | `'text'` / `'image'` / `'mixed'` |
| `getRaw()` | array | Vollständige Upstream-Payload für eine genauere Analyse |
| `getError()` | ?string | Fehlercode |
| `getMessage()` | ?string | Lesbare Fehlermeldung |

## Token-Typen

| Präfix | Quelle | Sicherheitsstufe |
|--------|--------|----------------|
| `pt_` | Haupt-API | Hoch |
| `offline_` | Backup-Dienst | Mittel |
| `dg_` | Haupt-API, eingeschränkter Modus (z. B. Kontingent erschöpft) | `isValid()` ist immer `false`; prüfen Sie `isDegraded()` und entscheiden Sie selbst |
| `client_` | Reiner Client-Platzhalter (native SDKs) | Keine — siehe unten |
| `co_` | Reiner Client-Platzhalter (Web-SDK) | Keine — siehe unten |

### Reine Client-Tokens (`client_` / `co_`)

Diese Platzhalter werden nur dann auf dem Gerät erzeugt, wenn **weder** die Haupt-API
**noch** der Backup-Dienst erreichbar waren. Sie tragen keine Signatur und haben keinen
Server-Eintrag. Seit v1.2.5 akzeptiert das SDK sie nicht mehr ungeprüft:

1. Es prüft die Haupt-API (2 s Timeout). Jede HTTP-Antwort bedeutet, dass kein Ausfall
   vorliegt → `isValid()=false`, `isClientOnly()=true`,
   `getError()='client_only_rejected_api_reachable'`.
2. Andernfalls prüft es die Backup-API (2 s Timeout). Jede HTTP-Antwort → wird ebenso
   abgelehnt (das Gerät hätte ein `offline_`-Token erhalten müssen).
3. Nur wenn beide nicht erreichbar sind (Netzwerkfehler / Timeout) → `isValid()=true`,
   `isClientOnly()=true`, `isOffline()=true`.

Die Prüfergebnisse werden zwischengespeichert: „erreichbar“ für 30 Sekunden, „nicht erreichbar“ für 5
Sekunden, damit bei einem Ausfall nicht jede Anfrage auf zwei Timeouts warten muss. Ist die
APCu-Erweiterung aktiviert, teilen sich alle PHP-FPM-Worker den Cache und nur ein Worker prüft einen
bestimmten Endpunkt gleichzeitig; ohne APCu gilt der Cache pro Prozess.
Mit `Client::isClientOnlyToken($token)` erkennen Sie diese Präfixe.

Für sensible Aktionen (Login, Zahlung) können Sie reine Client-Tokens auch während eines
Ausfalls ablehnen:

```php
$client->setRejectClientOnly(true);
// client_ / co_ tokens -> isValid()=false, getError()='client_only_rejected_by_policy'
```

## Vollständiges Beispiel

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

## Laravel-Integration

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

## Tests

```bash
# Install dependencies
composer install

# Run tests
composer test

# Integration tests (requires real credentials)
CAPTCHALA_APP_KEY=xxx CAPTCHALA_APP_SECRET=xxx composer test
```

## Lizenz

MIT

---

## Hilfsfunktionen für CMS-Plugins (`Captchala\Cms`)

Werden von den CMS-Plugins von CaptchaLa (WordPress, Joomla, Drupal, Magento, …) genutzt — die
meisten Integratoren brauchen sie nicht direkt.

### Aktionskonstanten

```php
use Captchala\Cms\Action;

$server = $client->issueServerToken(Action::LOGIN, $request->ip());
```

### Widget-Renderer

```php
use Captchala\Cms\Widget;
use Captchala\Cms\Action;

echo Widget::renderHtml($appKey, $serverToken, Action::LOGIN, [
    'product'      => 'bind',
    'lang'         => 'ja',
    'hidden_input' => true,   // also emits <input name="captchala_token">
]);
```

### Fehler-Normalisierung

```php
use Captchala\Cms\Errors;

$result = $client->validate($_POST['captchala_token']);
if (!$result->isValid()) {
    show_form_error(Errors::standardize($result->getError()));
}
```
