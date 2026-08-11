<?php

declare(strict_types=1);

namespace OCP {
    interface IL10N {
        public function t(string $text, array $parameters = [], ?int $count = null): string;
    }

    interface IConfig {
        public function getSystemValueString(string $key, string $default = ''): string;
    }

    interface IRequest {
        public function getParam(string $key, mixed $default = null): mixed;
    }

    interface IURLGenerator {
        public function imagePath(string $appName, string $file): string;
    }
}

namespace OCP\AppFramework\Services {
    interface IAppConfig {
        public function getAppValueString(string $key, string $default = ''): string;

        public function setAppValueString(string $key, string $value): void;
    }
}

namespace OCP\Http\Client {
    interface IResponse {
        public function getStatusCode(): int;

        public function getBody(): mixed;
    }

    interface IClient {
        /** @param array<string, mixed> $options */
        public function get(string $url, array $options = []): IResponse;
    }

    interface IClientService {
        public function newClient(): IClient;
    }
}

namespace OCP\AppFramework {
    use OCP\IRequest;

    class App {
        public function __construct(protected string $appName) {}
    }

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

    class TemplateResponse {
        /** @param array<string, mixed> $parameters */
        public function __construct(
            private string $appName,
            private string $templateName,
            private array $parameters = []
        ) {}
    }
}

namespace OCP\AppFramework\Http\Attribute {
    #[\Attribute(\Attribute::TARGET_METHOD)]
    class NoAdminRequired {}

    #[\Attribute(\Attribute::TARGET_METHOD)]
    class NoCSRFRequired {}
}

namespace OCP\Settings {
    use OCP\AppFramework\Http\TemplateResponse;

    interface ISettings {
        public function getForm(): TemplateResponse;

        public function getSection(): string;

        public function getPriority(): int;
    }

    interface IIconSection {
        public function getID(): string;

        public function getName(): string;

        public function getPriority(): int;

        public function getIcon(): string;
    }
}
