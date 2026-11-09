<?php

declare(strict_types=1);

namespace OCA\INValidator\Exception;

class DocumentInputException extends \RuntimeException
{
    public function __construct(
        string $message,
        private int $httpStatus = 400,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }
}
