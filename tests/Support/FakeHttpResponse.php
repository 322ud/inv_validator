<?php

declare(strict_types=1);

namespace OCA\INValidator\Tests\Support;

use OCP\Http\Client\IResponse;

final class FakeHttpResponse implements IResponse {
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
