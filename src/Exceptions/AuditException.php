<?php

namespace Uzapoint\Auditable\Exceptions;

class AuditException extends \RuntimeException
{
    /** @param  array<string, mixed>  $context  Extra details for logs, never shown to users. */
    public function __construct(
        string $message = '',
        public readonly array $context = [],
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public static function publishFailed(string $reason, array $context = []): self
    {
        return new self("Audit publish failed: {$reason}", $context);
    }

    public static function invalidPayload(string $reason): self
    {
        return new self("Invalid audit payload: {$reason}");
    }
}
