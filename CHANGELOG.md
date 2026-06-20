# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [1.2.0] - 2026-06-20

### Changed
- `Client::validate()` no longer sends `client_ip`; the dashboard no longer
  gates pass/fail on a caller-supplied IP. Cross-domain + dual-stack
  (IPv4/IPv6) made a solve-vs-submit IP comparison reject legitimate users
  (`binding_mismatch`). Matches Turnstile / reCAPTCHA (optional soft
  `remoteip`) and Geetest (records + returns the IP). The `$clientIp`
  parameter stays on the signature for backward compatibility but is ignored
  — **no breaking change**, this only relaxes a check.

### Added
- `ValidateResult::getUserIp()` — end-user IP recorded at solve time
  (informational, like Geetest `captcha_args.user_ip`). Other solve-context
  fields (e.g. `referer` for web) are returned by the dashboard in the raw
  validate response and are not surfaced as typed SDK getters.

## [1.0.0] - 2026-05-07

### Added
- `Captchala\Cms\Action` — 13 canonical action constants (login, register,
  comment, checkout, etc.) used by CMS plugins as the `action` argument to
  `Client::issueServerToken()` and the `data-action` widget attribute.
- `Captchala\Cms\Widget::renderHtml($appKey, $serverToken, $action, $opts)` —
  emits the exact `<div data-captchala …>` + `<script>` markup used by every
  CaptchaLa CMS plugin. Optional `product`, `lang`, `hidden_input`, `loader_url`.
- `Captchala\Cms\Errors::standardize(?string $code): string` — maps backend
  error codes to a small set of user-facing English messages. Plugins translate
  these through their host i18n stack.

### Changed
- Stabilised public API at 1.0.0. The `Captchala\Client` surface and all 0.3.x
  features (issueServerToken, moderationCheck/Text, validate w/ client_ip and
  bind_uid) are now committed under SemVer.

### Compatibility
- Strictly additive — every 0.3.0 / 0.2.0 / 0.1.0 call site compiles and
  behaves identically. No breaking changes.

## [0.3.0] - Unreleased

### Added
- `Client::issueServerToken(action, bindingIp?, ttl?, maxUses?, bindUid?): IssueResult` —
  mints a one-time `sct_` server token via `POST /v1/server/challenge/issue`.
  Hand the returned token to the browser SDK via the `serverToken` prop for
  the recommended production flow (single-use, action-scoped, optionally
  IP/UID-bound).
- `Client::moderationCheck(input, userId?): ModerationResult` — multi-modal
  content moderation (`POST /v1/moderation/check`). `input` is an array of
  `{type:text|image_url, ...}` items in OpenAI-compatible format.
- `Client::moderationText(text, userId?): ModerationResult` — convenience
  wrapper for plain-text moderation (`POST /v1/moderation/text`).
- New result classes `IssueResult` and `ModerationResult` mirror the
  `ValidateResult` shape — explicit getters, `toArray()`, error/message
  surfacing.
- Test coverage: transport-mocked happy path + omitted-optional-params +
  upstream-error propagation + transport-failure handling for all three
  new methods (11 new tests, 25 total, 92 assertions).

### Compatibility
- No breaking changes. New methods are additive; existing `validate()`
  call sites are unaffected.

## [0.2.0] - Unreleased

### Added
- `Client::validate()` now accepts an optional third argument
  `?string $clientIp = null`. When non-empty, it is forwarded to the backend
  as `client_ip` so that tokens issued with `bind_ip` can be verified. Empty
  string / `null` skips the field entirely — behaviour unchanged for existing
  call sites.
- `ValidateResult::getUid(): ?string` — returns the `uid` populated by the
  backend when the `pass_token` was issued against a `server_token` with
  `bind_uid`. Integrators can compare this against the expected user ID to
  verify the captcha was solved for the intended account.
- `ValidateResult::toArray()` now includes a `uid` key.
- Test coverage: transport-mocked happy path, client_ip body serialisation
  (present / omitted), uid parsing, `pt_`/`offline_`/`client_` routing,
  transport-failure handling, and upstream error propagation.

### Changed
- Internal refactor: HTTP is abstracted behind a private transport callable
  so tests can mock without Guzzle. Production behaviour (cURL first,
  streams fallback) is unchanged.
- Added `setBaseUrl()`, `setBackupUrl()` and `setTransport()` hooks for tests.
  They are opt-in and have no effect on existing users.

### Compatibility
- No breaking changes. The `validate()` signature adds only an optional
  parameter with a default value; pre-0.2.0 call sites compile and behave
  identically.

## [0.1.0]

- Initial release.
