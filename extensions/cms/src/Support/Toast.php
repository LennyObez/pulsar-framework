<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Support;

use JsonSerializable;
use Override;
use Pulsar\Api\Api;

/**
 * Immutable value object representing a toast notification.
 *
 * Serializable to JSON for transport via the X-CMS-Toast response header.
 */
#[Api(since: '1.0.0')]
final readonly class Toast implements JsonSerializable
{
    private function __construct(
        public string $message,
        public string $type,
        public int $duration = 5000,
    ) {}

    public static function success(string $message, int $duration = 5000): self
    {
        return new self($message, 'success', $duration);
    }

    public static function error(string $message, int $duration = 8000): self
    {
        return new self($message, 'error', $duration);
    }

    public static function info(string $message, int $duration = 5000): self
    {
        return new self($message, 'info', $duration);
    }

    public static function warning(string $message, int $duration = 6000): self
    {
        return new self($message, 'warning', $duration);
    }

    public function withMessage(string $message): self
    {
        return new self($message, $this->type, $this->duration);
    }

    /**
     * @return array{message: string, type: string, duration: int}
     */
    #[Override]
    public function jsonSerialize(): array
    {
        return [
            'message' => $this->message,
            'type' => $this->type,
            'duration' => $this->duration,
        ];
    }
}
