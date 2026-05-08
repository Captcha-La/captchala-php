<?php

declare(strict_types=1);

namespace Captchala;

/**
 * Content-moderation result.
 *
 * `flagged` is the boolean verdict from the upstream model. `categories`
 * is a key→bool map of which categories tripped (e.g. "violence", "hate").
 * Different upstream models surface different category sets; iterate
 * defensively.
 */
class ModerationResult
{
    private bool $ok;
    private bool $flagged;
    private array $categories;
    private array $raw;
    private ?string $contentType;
    private ?string $error;
    private ?string $message;

    public function __construct(
        bool $ok,
        bool $flagged = false,
        array $categories = [],
        array $raw = [],
        ?string $contentType = null,
        ?string $error = null,
        ?string $message = null
    ) {
        $this->ok = $ok;
        $this->flagged = $flagged;
        $this->categories = $categories;
        $this->raw = $raw;
        $this->contentType = $contentType;
        $this->error = $error;
        $this->message = $message;
    }

    public function isOk(): bool
    {
        return $this->ok;
    }

    /** True if upstream model flagged the content. */
    public function isFlagged(): bool
    {
        return $this->flagged;
    }

    /**
     * Map of category name → bool (true = tripped). Categories vary by
     * upstream model; do not assume a fixed set.
     */
    public function getCategories(): array
    {
        return $this->categories;
    }

    /** True if any of the given category names tripped. */
    public function hasCategory(string ...$names): bool
    {
        foreach ($names as $n) {
            if (!empty($this->categories[$n])) return true;
        }
        return false;
    }

    /** "text", "image", or "mixed" — what the request contained. */
    public function getContentType(): ?string
    {
        return $this->contentType;
    }

    /** Full upstream response payload, for advanced inspection. */
    public function getRaw(): array
    {
        return $this->raw;
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
            'flagged' => $this->flagged,
            'categories' => $this->categories,
            'content_type' => $this->contentType,
            'error' => $this->error,
            'message' => $this->message,
            'raw' => $this->raw,
        ];
    }
}
