<?php

declare(strict_types=1);

namespace OCA\KoSITValidator\Tests\Support;

final class FakeHttpResponse {
    public function __construct(
        private int $statusCode,
        private string $body
    ) {}

    public function getStatusCode(): int {
        return $this->statusCode;
    }

    public function getBody(): string {
        return $this->body;
    }
}
