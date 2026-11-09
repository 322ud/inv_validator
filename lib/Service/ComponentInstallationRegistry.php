<?php

declare(strict_types=1);

namespace OCA\INValidator\Service;

use OCA\INValidator\Exception\AdministrationException;
use OCP\AppFramework\Services\IAppConfig;

/** Durable ownership records in Nextcloud's app configuration, outside the app package. */
class ComponentInstallationRegistry
{
    public function __construct(private IAppConfig $appConfig)
    {
    }

    /** @return array<string, mixed>|null */
    public function get(string $component): ?array
    {
        $raw = $this->appConfig->getAppValueString($this->key($component), '');
        if ($raw === '') {
            return null;
        }
        try {
            $record = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new AdministrationException('Der Installationsnachweis ist beschädigt.', 0, $e);
        }
        if (
            !is_array($record) || ($record['component'] ?? null) !== $component
            || !is_array($record['artifacts'] ?? null) || !is_array($record['links'] ?? null)
        ) {
            throw new AdministrationException('Der Installationsnachweis ist ungültig.');
        }
        return $record;
    }

    /** Register ownership before the artifact is moved into its final destination.
     * @param array<string, string>|null $manifest NULL means an existing artifact is reused, not owned.
     */
    public function prepare(string $component, string $version, string $artifact, ?array $manifest): void
    {
        $record = $this->get($component) ?? [
            'component' => $component,
            'firstRecordedAt' => gmdate('c'),
            'artifacts' => [],
            'links' => [],
        ];
        $record['lastOperationAt'] = gmdate('c');
        $record['version'] = $version;
        $record['state'] = 'prepared';
        if ($manifest !== null) {
            $record['artifacts'][$artifact] = ['version' => $version, 'manifest' => $manifest];
        }
        $this->save($component, $record);
    }

    public function prepareLink(string $component, string $link, string $artifact): void
    {
        $record = $this->get($component);
        if ($record === null) {
            throw new AdministrationException('Installationsnachweis fehlt.');
        }
        $record['links'][$link] = $artifact;
        $this->save($component, $record);
    }

    public function activated(string $component, string $link, string $artifact): void
    {
        $record = $this->get($component);
        if ($record === null) {
            throw new AdministrationException('Installationsnachweis fehlt.');
        }
        $record['links'][$link] = $artifact;
        $record['state'] = 'installed';
        $record['lastOperationAt'] = gmdate('c');
        $this->save($component, $record);
    }

    public function removing(string $component): void
    {
        $record = $this->get($component);
        if ($record === null) {
            throw new AdministrationException('Installationsnachweis fehlt.');
        }
        $record['state'] = 'removing';
        $record['removalStartedAt'] = gmdate('c');
        $this->save($component, $record);
    }

    public function removed(string $component): void
    {
        $record = $this->get($component);
        if ($record === null) {
            return;
        }
        $record['state'] = 'removed';
        $record['removedAt'] = gmdate('c');
        $record['artifacts'] = [];
        $record['links'] = [];
        $this->save($component, $record);
    }

    /** @return array<string, string> */
    public function manifest(string $path): array
    {
        if (is_link($path)) {
            throw new AdministrationException('Symbolische Links in einer Komponente werden nicht entfernt.');
        }
        if (is_file($path)) {
            $hash = hash_file('sha256', $path);
            if ($hash === false) {
                throw new AdministrationException('Komponente konnte nicht gelesen werden.');
            }
            return ['' => $hash];
        }
        if (!is_dir($path)) {
            throw new AdministrationException('Komponentenverzeichnis fehlt.');
        }
        $manifest = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $entry) {
            $name = substr($entry->getPathname(), strlen($path) + 1);
            if ($entry->isLink() || (!$entry->isFile() && !$entry->isDir())) {
                throw new AdministrationException('Die Komponente enthält unbekannte Dateitypen.');
            }
            if ($entry->isDir()) {
                $manifest[$name . '/'] = 'directory';
            } else {
                $hash = hash_file('sha256', $entry->getPathname());
                if ($hash === false) {
                    throw new AdministrationException('Komponente konnte nicht gelesen werden.');
                }
                $manifest[$name] = $hash;
            }
        }
        ksort($manifest);
        return $manifest;
    }

    /** @param array<string, mixed> $record */
    private function save(string $component, array $record): void
    {
        $this->appConfig->setAppValueString($this->key($component), json_encode($record, JSON_THROW_ON_ERROR));
    }

    private function key(string $component): string
    {
        if (!in_array($component, ['validator', 'configuration'], true)) {
            throw new \InvalidArgumentException('Ungültige Komponente.');
        }
        return 'external_component_' . $component;
    }
}
