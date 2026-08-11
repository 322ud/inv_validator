<?php

declare(strict_types=1);

namespace OCA\KoSITValidator\Tests\Support;

use OCP\Http\Client\IClientService;
use OCP\Http\Client\IClient;

final class QueuedHttpClientService implements IClientService {
    /** @var list<FakeHttpResponse|\Throwable> */
    private array $responses;

    /** @var list<array{url: string, options: array<string, mixed>}> */
    public array $requests = [];

    /** @param list<FakeHttpResponse|\Throwable> $responses */
    public function __construct(array $responses) {
        $this->responses = $responses;
    }

    public function newClient(): IClient {
        return new class($this) implements IClient {
            public function __construct(private QueuedHttpClientService $service) {}

            /** @param array<string, mixed> $options */
            public function get(string $url, array $options = []): FakeHttpResponse {
                return $this->service->respond($url, $options);
            }
        };
    }

    /** @param array<string, mixed> $options */
    public function respond(string $url, array $options): FakeHttpResponse {
        $this->requests[] = ['url' => $url, 'options' => $options];
        $response = array_shift($this->responses);

        if ($response instanceof \Throwable) {
            throw $response;
        }

        if (!$response instanceof FakeHttpResponse) {
            throw new \RuntimeException('No fake HTTP response queued');
        }

        return $response;
    }
}
