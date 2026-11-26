# Captchala PHP SDK

[English](https://github.com/Captcha-La/captchala-php/blob/main/README.md) · [简体中文](https://github.com/Captcha-La/captchala-php/blob/main/README_zh.md) · [日本語](https://github.com/Captcha-La/captchala-php/blob/main/README.ja.md) · [한국어](https://github.com/Captcha-La/captchala-php/blob/main/README.ko.md) · [Español](https://github.com/Captcha-La/captchala-php/blob/main/README.es.md) · **Português (BR)** · [Deutsch](https://github.com/Captcha-La/captchala-php/blob/main/README.de.md)

SDK de servidor para validar tokens de Captcha.

- [Site](https://captcha.la)
- [Documentação](https://docs.captcha.la/sdk/server-php)
- [Documentação do Web SDK](https://docs.captcha.la/web-sdk)
- [Painel](https://dash.captcha.la)

## Instalação

```bash
composer require captchala/captchala-php
```

## Início rápido

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

## Referência da API

### `Client::__construct(string $appKey, string $appSecret, int $timeout = 5)`

Cria uma instância do cliente.

- `$appKey` - App Key (obtida no painel)
- `$appSecret` - App Secret (obtido no painel)
- `$timeout` - Tempo limite da requisição em segundos (padrão: 5)

### `Client::validate(string $token, bool $keepToken = false, ?string $clientIp = null): ValidateResult`

Valida um token.

- `$token` - O pass_token recebido do SDK de frontend
- `$keepToken` - Se for true, o token não é consumido (pode ser validado de novo)
- `$clientIp` - *(opcional, recomendado)* O IP do usuário final obtido da requisição
  recebida. Usado em verificações de risco adicionais. Pode ser omitido com segurança.

```php
$result = $client->validate($token, false, $request->ip());
if ($result->isValid()) {
    // ... let the request through ...
}
```

### Métodos de `ValidateResult`

| Método | Tipo de retorno | Descrição |
|--------|-------------|-------------|
| `isValid()` | bool | Se a validação passou |
| `isOffline()` | bool | Se foi uma verificação offline |
| `isClientOnly()` | bool | Se é um token somente de cliente |
| `getError()` | ?string | Retorna a mensagem de erro |
| `getWarning()` | ?string | Retorna a mensagem de aviso |
| `getChallengeId()` | ?string | Retorna o ID do desafio |
| `getAction()` | ?string | Retorna a ação de negócio |
| `getUid()` | ?string | ID do usuário vinculado via `bind_uid` — confira se o pass_token pertence ao usuário esperado |
| `getUserIp()` | ?string | IP do usuário final registrado no momento da resolução (informativo). |
| `getCaptchaArgs()` | array | Eco do contexto da resolução (`platform`, `user_ip`, `referer`, `pkg`, `solved_at`, `risk_score`). Tudo é informativo. |
| `toArray()` | array | Converte para array |

### Eco do contexto da resolução (`captcha_args`)

`getCaptchaArgs()` retorna o que a plataforma registrou **no momento da resolução**. Use
para logs ou para sua própria pontuação de risco, nunca como critério de aprovação/reprovação:

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

### Verificando `bind_uid`

Se você emitiu o `server_token` com `bind_uid = 'user_42'`, compare o
resultado com o usuário esperado:

```php
$result = $client->validate($token);
if ($result->isValid() && $result->getUid() !== $expectedUserId) {
    // pass_token was issued for a different user — reject
}
```

### `Client::issueServerToken(string $action, ?string $bindingIp = null, ?int $ttl = null, ?int $maxUses = null, ?string $bindUid = null): IssueResult`

Emite um server token `sct_` de uso único. Passe o token retornado para o SDK do
navegador pela prop `serverToken`: ele é de uso único, restrito a uma ação e,
opcionalmente, vinculado a IP/UID.

- `$action` - Cenário de negócio (`login`, `register`, `payment`, …)
- `$bindingIp` - *(opcional)* IP do usuário final; o backend rejeita o token se outro IP tentar usá-lo
- `$ttl` - *(opcional)* Tempo de vida em segundos; o servidor impõe um limite máximo (padrão 300)
- `$maxUses` - *(opcional)* Limite de novas tentativas do SDK; a verificação continua sendo de passagem única
- `$bindUid` - *(opcional)* ID do usuário; compare com `ValidateResult::getUid()` na verificação

```php
$issue = $client->issueServerToken('login', $request->ip(), 300, 5, $user->id);
if (!$issue->isOk()) {
    return ['error' => $issue->getError()];   // rate_limit_exceeded, ...
}
return ['server_token' => $issue->getToken()];   // hand to browser
```

| Método de `IssueResult` | Retorno | Descrição |
|---|---|---|
| `isOk()` | bool | A emissão foi bem-sucedida |
| `getToken()` | ?string | O server token `sct_<hex>` |
| `getExpiresIn()` | ?int | TTL em segundos |
| `getIssuedAt()` | ?int | Timestamp Unix (segundos) |
| `getError()` | ?string | Código de erro |
| `getMessage()` | ?string | Mensagem de erro legível |

### `Client::moderationCheck(array $input, ?string $userId = null): ModerationResult`

Moderação de conteúdo multimodal. `$input` é uma lista de itens `{type, ...}` no
formato compatível com a OpenAI: text e image_url podem ser misturados na mesma chamada.

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

Atalho para moderar texto puro.

```php
$result = $client->moderationText('user comment here', $user->id);
```

| Método de `ModerationResult` | Retorno | Descrição |
|---|---|---|
| `isOk()` | bool | A requisição foi bem-sucedida (independentemente de `flagged`) |
| `isFlagged()` | bool | Veredito do modelo upstream |
| `hasCategory(...$names)` | bool | True se qualquer uma das categorias indicadas foi acionada |
| `getCategories()` | array | Mapa `category → bool`; as categorias variam conforme o modelo upstream |
| `getContentType()` | ?string | `'text'` / `'image'` / `'mixed'` |
| `getRaw()` | array | Payload completo do upstream para inspeção avançada |
| `getError()` | ?string | Código de erro |
| `getMessage()` | ?string | Mensagem de erro legível |

## Tipos de token

| Prefixo | Origem | Nível de segurança |
|--------|--------|----------------|
| `pt_` | API principal | Alto |
| `offline_` | Serviço de backup | Médio |
| `dg_` | API principal, modo degradado (ex.: cota esgotada) | `isValid()` é sempre `false`; verifique `isDegraded()` e decida você mesmo |
| `client_` | Placeholder somente de cliente (SDKs nativos) | Nenhum — veja abaixo |
| `co_` | Placeholder somente de cliente (Web SDK) | Nenhum — veja abaixo |

### Tokens somente de cliente (`client_` / `co_`)

Esses placeholders são gerados no dispositivo apenas quando **tanto** a API principal
**quanto** o serviço de backup estavam inacessíveis. Eles não têm assinatura nem registro no
servidor. Desde a v1.2.5, o SDK não os aceita mais às cegas:

1. Ele sonda a API principal (tempo limite de 2 s). Qualquer resposta HTTP significa que não há
   indisponibilidade → `isValid()=false`, `isClientOnly()=true`,
   `getError()='client_only_rejected_api_reachable'`.
2. Caso contrário, sonda a API de backup (tempo limite de 2 s). Qualquer resposta HTTP → rejeitado
   da mesma forma (o dispositivo deveria ter recebido um token `offline_`).
3. Somente se ambas estiverem inacessíveis (erro de rede / tempo esgotado) → `isValid()=true`,
   `isClientOnly()=true`, `isOffline()=true`.

Os resultados da sondagem ficam em cache: "acessível" por 30 segundos, "inacessível" por 5
segundos, para que uma indisponibilidade não faça cada requisição esperar dois tempos limite. Com a
extensão APCu ativada, o cache é compartilhado por todos os workers do PHP-FPM e apenas um worker
sonda um determinado endpoint por vez; sem APCu, o cache é por processo.
Use `Client::isClientOnlyToken($token)` para detectar esses prefixos.

Para ações sensíveis (login, pagamento), você pode recusar tokens somente de cliente mesmo
durante uma indisponibilidade:

```php
$client->setRejectClientOnly(true);
// client_ / co_ tokens -> isValid()=false, getError()='client_only_rejected_by_policy'
```

## Exemplo completo

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

## Integração com Laravel

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

## Testes

```bash
# Install dependencies
composer install

# Run tests
composer test

# Integration tests (requires real credentials)
CAPTCHALA_APP_KEY=xxx CAPTCHALA_APP_SECRET=xxx composer test
```

## Licença

MIT

---

## Utilitários para plugins de CMS (`Captchala\Cms`)

Usados pelos plugins de CMS da CaptchaLa (WordPress, Joomla, Drupal, Magento, …) — a maioria
dos integradores não precisa usá-los diretamente.

### Constantes de ação

```php
use Captchala\Cms\Action;

$server = $client->issueServerToken(Action::LOGIN, $request->ip());
```

### Renderizador do widget

```php
use Captchala\Cms\Widget;
use Captchala\Cms\Action;

echo Widget::renderHtml($appKey, $serverToken, Action::LOGIN, [
    'product'      => 'bind',
    'lang'         => 'ja',
    'hidden_input' => true,   // also emits <input name="captchala_token">
]);
```

### Padronizador de erros

```php
use Captchala\Cms\Errors;

$result = $client->validate($_POST['captchala_token']);
if (!$result->isValid()) {
    show_form_error(Errors::standardize($result->getError()));
}
```
