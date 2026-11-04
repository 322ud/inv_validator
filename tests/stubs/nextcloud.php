<?php

declare(strict_types=1);

namespace OCP {
    interface IL10N {
        public function t(string $text, array $parameters = [], ?int $count = null): string;
    }

    interface IConfig {
        public function getSystemValueString(string $key, string $default = ''): string;

        public function getAppValue(string $appName, string $key, string $default = ''): string;
    }

    interface IRequest {
        public function getParam(string $key, mixed $default = null): mixed;

        /** @return array<string, mixed>|null */
        public function getUploadedFile(string $key): ?array;
    }

    interface IURLGenerator {
        public function imagePath(string $appName, string $file): string;

        /** @param array<string, mixed> $args */
        public function linkToRoute(string $routeName, array $args = []): string;
    }

    interface IUser {
        public function getUID(): string;
    }

    interface IUserSession {
        public function getUser(): ?IUser;
    }

    interface IGroupManager {
        public function isAdmin(string $userId): bool;
    }

    interface INavigationManager {
        /** @param array<string, mixed>|callable(): array<string, mixed> $entry */
        public function add(array|callable $entry): void;
    }
}

namespace OCP\App {
    interface IAppManager {
        public function isEnabledForUser(string $appId, ?\OCP\IUser $user = null): bool;
    }
}

namespace OCP\AppFramework\Bootstrap {
    interface IBootstrap {
        public function register(IRegistrationContext $context): void;

        public function boot(IBootContext $context): void;
    }

    interface IRegistrationContext {
        public function registerEventListener(string $event, string $listener, int $priority = 0): void;
    }

    interface IBootContext {}
}

namespace OCP\Navigation\Events {
    class LoadAdditionalEntriesEvent extends \OCP\EventDispatcher\Event {}
}

namespace OCP\AppFramework\Services {
    interface IAppConfig {
        public function getAppValueString(string $key, string $default = ''): string;

        public function setAppValueString(string $key, string $value): void;
    }
}

namespace OCP\EventDispatcher {
    class Event {}

    /** @template T of Event */
    interface IEventListener {
        public function handle(Event $event): void;
    }

    interface IEventDispatcher {
        public function dispatchTyped(Event $event): void;
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

        /** @return array<string, mixed> */
        public function getParams(): array {
            return $this->parameters;
        }
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
