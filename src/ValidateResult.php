<?php

declare(strict_types=1);

namespace Captchala;

/**
 * Token validation result
 */
class ValidateResult
{
    private bool $valid;
    private bool $offline;
    private bool $clientOnly;
    private ?string $error;
    private ?string $warning;
    private ?string $challengeId;
    private ?string $action;
    private ?string $uid;

    public function __construct(
        bool $valid,
        bool $offline = false,
        bool $clientOnly = false,
        ?string $error = null,
        ?string $warning = null,
        ?string $challengeId = null,
        ?string $action = null,
        ?string $uid = null
    ) {
        $this->valid = $valid;
        $this->offline = $offline;
        $this->clientOnly = $clientOnly;
        $this->error = $error;
        $this->warning = $warning;
        $this->challengeId = $challengeId;
        $this->action = $action;
        $this->uid = $uid;
    }

    /**
     * Check if validation passed
     */
    public function isValid(): bool
    {
        return $this->valid;
    }

    /**
     * Check if this was offline verification
     */
    public function isOffline(): bool
    {
        return $this->offline;
    }

    /**
     * Check if this is a client-only token (cannot be verified server-side)
     */
    public function isClientOnly(): bool
    {
        return $this->clientOnly;
    }

    /**
     * Get error message
     */
    public function getError(): ?string
    {
        return $this->error;
    }

    /**
     * Get warning message
     */
    public function getWarning(): ?string
    {
        return $this->warning;
    }

    /**
     * Get challenge ID
     */
    public function getChallengeId(): ?string
    {
        return $this->challengeId;
    }

    /**
     * Get business action
     */
    public function getAction(): ?string
    {
        return $this->action;
    }

    /**
     * Get the user ID bound via bind_uid at server_token issuance time.
     *
     * Use this to verify the pass_token was issued for the expected user.
     * Returns null if the server_token was not issued with bind_uid.
     */
    public function getUid(): ?string
    {
        return $this->uid;
    }

    /**
     * Convert to array
     */
    public function toArray(): array
    {
        return [
            'valid' => $this->valid,
            'offline' => $this->offline,
            'client_only' => $this->clientOnly,
            'error' => $this->error,
            'warning' => $this->warning,
            'challenge_id' => $this->challengeId,
            'action' => $this->action,
            'uid' => $this->uid,
        ];
    }
}
