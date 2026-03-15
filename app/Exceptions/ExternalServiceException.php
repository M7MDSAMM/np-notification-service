<?php

namespace App\Exceptions;

use RuntimeException;

class ExternalServiceException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $statusCode = 502,
        public readonly array $context = [],
        public readonly ?string $errorCode = null,
        public readonly ?string $correlationId = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }
}
