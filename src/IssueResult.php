<?php

declare(strict_types=1);

namespace Captchala;

/**
 * Server-token issuance result.
 *
 * On success, ->isOk() is true and ->getToken() returns the sct_ prefixed
 * one-time token to hand to the browser SDK via the `serverToken` prop.
 *
 * On failure, ->isOk() is false and ->getError() returns the error code
 * (e.g. 'rate_limit_exceeded', 'invalid_action', 'app_disabled').
 */
class IssueResult
{
    private bool $ok;
    private ?string $token;
    private ?int $expiresIn;
    private ?int $issuedAt;
    private ?string $error;
    private ?string $message;

    public function __construct(
        bool $ok,
        ?string $token = null,
        ?int $expiresIn = null,
        ?int $issuedAt = null,
        ?string $error = null,
        ?string $message = null
    ) {
        $this->ok = $ok;
        $this->token = $token;
        $this->expiresIn = $expiresIn;
        $this->issuedAt = $issuedAt;
        $this->error = $error;
        $this->message = $message;
    }

    public function isOk(): bool
    {
        return $this->ok;
    }

    public function getToken(): ?string
    {
        return $this->token;
    }

    /** TTL in seconds. */
    public function getExpiresIn(): ?int
    {
        return $this->expiresIn;
    }

    /** Unix timestamp (seconds) when the token was issued. */
    public function getIssuedAt(): ?int
    {
        return $this->issuedAt;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'token' => $this->token,
            'expires_in' => $this->expiresIn,
            'issued_at' => $this->issuedAt,
            'error' => $this->error,
            'message' => $this->message,
        ];
    }
}
