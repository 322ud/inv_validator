<?php

declare(strict_types=1);

namespace OCA\INValidator\Service;

use OCA\INValidator\Exception\AdministrationException;

/** Removes only unchanged artifacts for which this app has a durable ownership record. */
class ComponentRemovalService
{
    public function __construct(private ValidatorConfigService $config)
    {
    }

    /** @return list<array<string, mixed>> */
    public function pending(): array
    {
        $result = [];
        foreach (['validator' => 'KoSIT-Validator', 'configuration' => 'XRechnung-Konfiguration'] as $component => $label) {
            $record = $this->config->getInstallationRegistry()->get($component);
            if ($record === null || ($record['state'] ?? null) === 'removed') {
                continue;
            }
            $result[] = [
                'component' => $component,
                'label' => $label,
                'version' => $record['version'] ?? null,
                'lastOperationAt' => $record['lastOperationAt'] ?? null,
                'state' => $record['state'] ?? null,
                'paths' => array_values(array_unique(array_merge(array_keys($record['artifacts']), array_keys($record['links'])))),
                'recordToken' => hash('sha256', json_encode($record, JSON_THROW_ON_ERROR)),
            ];
        }
        return $result;
    }

    public function remove(string $component, bool $confirmed, string $recordToken): void
    {
        if (!$confirmed) {
            throw new \InvalidArgumentException('Die Komponente muss zum Entfernen ausgewählt sein.');
        }
        $registry = $this->config->getInstallationRegistry();
        // Same lock as installation and updates; re-read the record after acquiring it.
        $lock = @fopen(dirname($this->config->getValidatorJar()) . '/.kosit-install.lock', 'c');
        if ($lock === false) {
            throw new AdministrationException('Die Komponentensperre konnte nicht geöffnet werden.');
        }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new AdministrationException('Eine Komponentenaktion läuft bereits.');
            }
            $record = $registry->get($component);
            if (
                $record === null || ($record['state'] ?? null) === 'removed'
                || !hash_equals(hash('sha256', json_encode($record, JSON_THROW_ON_ERROR)), $recordToken)
            ) {
                throw new AdministrationException('Der Installationsnachweis wurde geändert. Bitte erneut prüfen und bestätigen.');
            }
            $artifacts = $record['artifacts'];
            $links = $record['links'];
            // Validate the entire deletion plan before changing any external file.
            foreach ($artifacts as $path => $artifact) {
                $this->validatePath($path, $component, false);
                if (!is_array($artifact) || !is_array($artifact['manifest'] ?? null)) {
                    throw new AdministrationException('Ungültiger Dateinachweis.');
                }
                $this->validateManifest($artifact['manifest'], $component);
                $currentManifest = file_exists($path) && !is_link($path) ? $registry->manifest($path) : [];
                $matches = ($record['state'] ?? null) === 'removing'
                    ? array_diff_assoc($currentManifest, $artifact['manifest']) === []
                    : $currentManifest === $artifact['manifest'];
                if (is_link($path) || (file_exists($path) && !$matches)) {
                    throw new AdministrationException('Komponente wurde außerhalb der App verändert und bleibt erhalten: ' . $path);
                }
            }
            foreach ($links as $link => $target) {
                $this->validatePath($link, $component, true);
                if (!is_string($target)) {
                    throw new AdministrationException('Ungültiger Linknachweis.');
                }
                if (is_link($link)) {
                    $actual = readlink($link);
                    $resolved = is_string($actual) && str_starts_with($actual, '/') ? $actual : dirname($link) . '/' . $actual;
                    if ($resolved !== $target && !array_key_exists($resolved, $artifacts)) {
                        throw new AdministrationException('Der Komponentenlink wurde verändert und bleibt erhalten: ' . $link);
                    }
                } elseif (file_exists($link)) {
                    throw new AdministrationException('Der Komponentenpfad ist kein verwalteter Link und bleibt erhalten: ' . $link);
                }
            }
            // Prevent an enabled startup update from immediately installing the component again.
            $updates = $this->config->getAutoUpdates();
            $this->config->saveAutoUpdates(
                $component === 'validator' ? false : $updates['validator'],
                $component === 'configuration' ? false : $updates['configuration']
            );
            $registry->removing($component);
            foreach ($links as $link => $target) {
                if (is_link($link) && !unlink($link)) {
                    throw new AdministrationException('Komponentenlink konnte nicht entfernt werden: ' . $link);
                }
            }
            foreach ($artifacts as $path => $artifact) {
                if (!file_exists($path)) {
                    continue;
                }
                if (is_file($path)) {
                    if (!unlink($path)) {
                        throw new AdministrationException('Komponente konnte nicht entfernt werden: ' . $path);
                    }
                    continue;
                }
                $names = array_map('strval', array_keys($artifact['manifest']));
                usort($names, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
                foreach ($names as $name) {
                    $entry = $path . '/' . rtrim($name, '/');
                    if (!file_exists($entry)) {
                        continue;
                    }
                    $removed = str_ends_with($name, '/') ? rmdir($entry) : unlink($entry);
                    if (!$removed) {
                        throw new AdministrationException('Komponentendatei konnte nicht entfernt werden: ' . $entry);
                    }
                }
                if (!rmdir($path)) {
                    throw new AdministrationException('Komponentenverzeichnis konnte nicht entfernt werden: ' . $path);
                }
            }
            $registry->removed($component);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param array<array-key, mixed> $manifest */
    private function validateManifest(array $manifest, string $component): void
    {
        foreach ($manifest as $name => $hash) {
            $name = (string)$name;
            if ($component === 'validator' && $name !== '') {
                throw new AdministrationException('Ungültiger JAR-Dateinachweis.');
            }
            if ($component === 'configuration') {
                $segments = explode('/', rtrim($name, '/'));
                if (
                    $name === '' || str_starts_with($name, '/') || str_contains($name, "\0")
                    || in_array('..', $segments, true) || in_array('.', $segments, true) || in_array('', $segments, true)
                ) {
                    throw new AdministrationException('Unsicherer Dateiname im Installationsnachweis.');
                }
            }
            $directory = $component === 'configuration' && str_ends_with($name, '/');
            if (($directory && $hash !== 'directory') || (!$directory && (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/', $hash)))) {
                throw new AdministrationException('Ungültige Prüfsumme im Installationsnachweis.');
            }
        }
    }

    private function validatePath(mixed $path, string $component, bool $link): void
    {
        if (
            !is_string($path) || !str_starts_with($path, '/') || str_contains($path, "\0")
            || in_array('..', explode('/', $path), true) || dirname($path) === '/'
        ) {
            throw new AdministrationException('Unsicherer Komponentenpfad.');
        }
        $parent = dirname($path);
        if (file_exists($parent) && realpath($parent) !== $parent) {
            throw new AdministrationException('Der Komponentenpfad enthält umgeleitete Verzeichnisse: ' . $path);
        }
        if (!$link) {
            $pattern = $component === 'validator' ? '/^validator-[0-9]+(?:\.[0-9]+)+(?:[-+][A-Za-z0-9.-]+)?-standalone\.jar$/' : '/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/';
            if (!preg_match($pattern, basename($path)) || ($component === 'configuration' && basename($parent) !== 'releases')) {
                throw new AdministrationException('Der Pfad ist kein verwaltetes Komponentenrelease.');
            }
        }
    }
}
