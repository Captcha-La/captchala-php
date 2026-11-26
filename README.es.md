# Captchala PHP SDK

[English](https://github.com/Captcha-La/captchala-php/blob/main/README.md) · [简体中文](https://github.com/Captcha-La/captchala-php/blob/main/README_zh.md) · [日本語](https://github.com/Captcha-La/captchala-php/blob/main/README.ja.md) · [한국어](https://github.com/Captcha-La/captchala-php/blob/main/README.ko.md) · **Español** · [Português (BR)](https://github.com/Captcha-La/captchala-php/blob/main/README.pt-BR.md) · [Deutsch](https://github.com/Captcha-La/captchala-php/blob/main/README.de.md)

SDK de servidor para validar tokens de Captcha.

- [Sitio web](https://captcha.la)
- [Documentación](https://docs.captcha.la/sdk/server-php)
- [Documentación del Web SDK](https://docs.captcha.la/web-sdk)
- [Panel](https://dash.captcha.la)

## Instalación

```bash
composer require captchala/captchala-php
```

## Inicio rápido

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

## Referencia de la API

### `Client::__construct(string $appKey, string $appSecret, int $timeout = 5)`

Crea una instancia del cliente.

- `$appKey` - App Key (del panel)
- `$appSecret` - App Secret (del panel)
- `$timeout` - Tiempo de espera de la petición en segundos (por defecto: 5)

### `Client::validate(string $token, bool $keepToken = false, ?string $clientIp = null): ValidateResult`

Valida un token.

- `$token` - El pass_token recibido del SDK de frontend
- `$keepToken` - Si es true, el token no se consume (se puede volver a validar)
- `$clientIp` - *(opcional, recomendado)* La IP del usuario final tomada de la petición
  entrante. Se usa para comprobaciones de riesgo adicionales. Se puede omitir sin problema.

```php
$result = $client->validate($token, false, $request->ip());
if ($result->isValid()) {
    // ... let the request through ...
}
```

### Métodos de `ValidateResult`

| Método | Tipo de retorno | Descripción |
|--------|-------------|-------------|
| `isValid()` | bool | Si la validación fue correcta |
| `isOffline()` | bool | Si fue una verificación offline |
| `isClientOnly()` | bool | Si es un token solo de cliente |
| `getError()` | ?string | Obtiene el mensaje de error |
| `getWarning()` | ?string | Obtiene el mensaje de advertencia |
| `getChallengeId()` | ?string | Obtiene el ID del desafío |
| `getAction()` | ?string | Obtiene la acción de negocio |
| `getUid()` | ?string | ID de usuario vinculado mediante `bind_uid`: comprueba que el pass_token pertenece al usuario esperado |
| `getUserIp()` | ?string | IP del usuario final registrada al resolver el desafío (informativa). |
| `getCaptchaArgs()` | array | Eco del contexto de resolución (`platform`, `user_ip`, `referer`, `pkg`, `solved_at`, `risk_score`). Todo es informativo. |
| `toArray()` | array | Convierte a array |

### Eco del contexto de resolución (`captcha_args`)

`getCaptchaArgs()` devuelve lo que la plataforma registró **en el momento de la resolución**.
Úsalo para registros o para tu propia puntuación de riesgo, nunca como criterio de aprobado/rechazado:

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

### Verificar `bind_uid`

Si emitiste el `server_token` con `bind_uid = 'user_42'`, compara el
resultado con el usuario esperado:

```php
$result = $client->validate($token);
if ($result->isValid() && $result->getUid() !== $expectedUserId) {
    // pass_token was issued for a different user — reject
}
```

### `Client::issueServerToken(string $action, ?string $bindingIp = null, ?int $ttl = null, ?int $maxUses = null, ?string $bindUid = null): IssueResult`

Emite un server token `sct_` de un solo uso. Pasa el token devuelto al SDK del
navegador mediante la prop `serverToken`: es de un solo uso, está limitado a una acción
y, opcionalmente, vinculado a una IP o UID.

- `$action` - Escenario de negocio (`login`, `register`, `payment`, …)
- `$bindingIp` - *(opcional)* IP del usuario final; el backend rechaza el token si lo canjea otra IP
- `$ttl` - *(opcional)* Vida útil en segundos; el servidor aplica un límite superior (por defecto 300)
- `$maxUses` - *(opcional)* Presupuesto de reintentos del SDK; la verificación sigue siendo de un solo paso
- `$bindUid` - *(opcional)* ID de usuario; compáralo con `ValidateResult::getUid()` al verificar

```php
$issue = $client->issueServerToken('login', $request->ip(), 300, 5, $user->id);
if (!$issue->isOk()) {
    return ['error' => $issue->getError()];   // rate_limit_exceeded, ...
}
return ['server_token' => $issue->getToken()];   // hand to browser
```

| Método de `IssueResult` | Retorno | Descripción |
|---|---|---|
| `isOk()` | bool | La emisión fue correcta |
| `getToken()` | ?string | El server token `sct_<hex>` |
| `getExpiresIn()` | ?int | TTL en segundos |
| `getIssuedAt()` | ?int | Marca de tiempo Unix (segundos) |
| `getError()` | ?string | Código de error |
| `getMessage()` | ?string | Mensaje de error legible |

### `Client::moderationCheck(array $input, ?string $userId = null): ModerationResult`

Moderación de contenido multimodal. `$input` es una lista de elementos `{type, ...}` en
formato compatible con OpenAI: puedes mezclar text e image_url en una misma llamada.

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

Atajo para moderar texto plano.

```php
$result = $client->moderationText('user comment here', $user->id);
```

| Método de `ModerationResult` | Retorno | Descripción |
|---|---|---|
| `isOk()` | bool | La petición fue correcta (independientemente de `flagged`) |
| `isFlagged()` | bool | Veredicto del modelo upstream |
| `hasCategory(...$names)` | bool | True si se activó alguna de las categorías indicadas |
| `getCategories()` | array | Mapa `category → bool`; las categorías dependen del modelo upstream |
| `getContentType()` | ?string | `'text'` / `'image'` / `'mixed'` |
| `getRaw()` | array | Payload completo del upstream para inspección avanzada |
| `getError()` | ?string | Código de error |
| `getMessage()` | ?string | Mensaje de error legible |

## Tipos de token

| Prefijo | Origen | Nivel de seguridad |
|--------|--------|----------------|
| `pt_` | API principal | Alto |
| `offline_` | Servicio de respaldo | Medio |
| `dg_` | API principal, modo degradado (p. ej., cuota agotada) | `isValid()` siempre es `false`; comprueba `isDegraded()` y decide tú |
| `client_` | Marcador solo de cliente (SDK nativos) | Ninguno, ver abajo |
| `co_` | Marcador solo de cliente (Web SDK) | Ninguno, ver abajo |

### Tokens solo de cliente (`client_` / `co_`)

Estos marcadores se generan en el dispositivo solo cuando **ni** la API principal
**ni** el servicio de respaldo estaban accesibles. No llevan firma ni registro en el
servidor. Desde la v1.2.5, el SDK ya no los acepta a ciegas:

1. Sondea la API principal (tiempo de espera de 2 s). Cualquier respuesta HTTP significa que no
   hay caída → `isValid()=false`, `isClientOnly()=true`,
   `getError()='client_only_rejected_api_reachable'`.
2. Si no, sondea la API de respaldo (tiempo de espera de 2 s). Cualquier respuesta HTTP → se rechaza
   de la misma forma (el dispositivo debería haber recibido un token `offline_`).
3. Solo si ambas son inaccesibles (error de red / tiempo de espera agotado) → `isValid()=true`,
   `isClientOnly()=true`, `isOffline()=true`.

Los resultados del sondeo se guardan en caché: "accesible" durante 30 segundos, "inaccesible" durante 5
segundos, para que una caída no obligue a cada petición a esperar dos tiempos de espera. Con la
extensión APCu habilitada, la caché la comparten todos los workers de PHP-FPM y solo un worker
sondea un endpoint concreto a la vez; sin APCu, la caché es por proceso.
Usa `Client::isClientOnlyToken($token)` para detectar estos prefijos.

Para acciones sensibles (inicio de sesión, pagos) puedes rechazar los tokens solo de cliente incluso
durante una caída:

```php
$client->setRejectClientOnly(true);
// client_ / co_ tokens -> isValid()=false, getError()='client_only_rejected_by_policy'
```

## Ejemplo completo

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

## Integración con Laravel

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

## Pruebas

```bash
# Install dependencies
composer install

# Run tests
composer test

# Integration tests (requires real credentials)
CAPTCHALA_APP_KEY=xxx CAPTCHALA_APP_SECRET=xxx composer test
```

## Licencia

MIT

---

## Utilidades para plugins de CMS (`Captchala\Cms`)

Las usan los plugins de CMS de CaptchaLa (WordPress, Joomla, Drupal, Magento, …); la mayoría
de los integradores no necesitan usarlas directamente.

### Constantes de acción

```php
use Captchala\Cms\Action;

$server = $client->issueServerToken(Action::LOGIN, $request->ip());
```

### Renderizador del widget

```php
use Captchala\Cms\Widget;
use Captchala\Cms\Action;

echo Widget::renderHtml($appKey, $serverToken, Action::LOGIN, [
    'product'      => 'bind',
    'lang'         => 'ja',
    'hidden_input' => true,   // also emits <input name="captchala_token">
]);
```

### Normalizador de errores

```php
use Captchala\Cms\Errors;

$result = $client->validate($_POST['captchala_token']);
if (!$result->isValid()) {
    show_form_error(Errors::standardize($result->getError()));
}
```
