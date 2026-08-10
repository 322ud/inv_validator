<?php

declare(strict_types=1);

namespace OCP {
    interface IConfig {
        public function getSystemValueString(string $key, string $default = ''): string;
    }

    interface IRequest {
        public function getParam(string $key, mixed $default = null): mixed;
    }
}

namespace OCP\AppFramework\Services {
    interface IAppConfig {
        public function getAppValueString(string $key, string $default = ''): string;

        public function setAppValueString(string $key, string $value): void;
    }
}

namespace OCP\Http\Client {
    interface IClientService {
        public function newClient(): object;
    }
}

namespace OCP\AppFramework {
    use OCP\IRequest;

    class Controller {
        public function __construct(
            protected string $appName,
            protected IRequest $request
        ) {}
    }
}

namespace OCP\AppFramework\Http {
    class DataResponse {
        public function __construct(
            private mixed $data = [],
            private int $status = 200
        ) {}

        public function getData(): mixed {
            return $this->data;
        }

        public function getStatus(): int {
            return $this->status;
        }
    }
}

namespace OCP\AppFramework\Http\Attribute {
    #[\Attribute(\Attribute::TARGET_METHOD)]
    class NoAdminRequired {}
}
