<?php

namespace Uzapoint\Auditable\Exceptions;

class AuditException extends \RuntimeException
{
    public static function publishFailed(string $reason, array $context = []): self
    {
        return new self("Audit publish failed: {$reason}", 0, null, $context);
    }

    public static function invalidPayload(string $reason): self
    {
        return new self("Invalid audit payload: {$reason}");
    }
}