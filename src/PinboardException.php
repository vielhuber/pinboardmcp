<?php
declare(strict_types=1);

namespace vielhuber\pinboardmcp;

final class PinboardException extends \RuntimeException
{
    /**
     * Reject a request without exposing credentials or response bodies.
     */
    public static function failed(string $message, int $code = 0): self
    {
        return new self($message, $code);
    }
}
