<?php

declare(strict_types=1);

namespace OCA\INValidator\Service;

use OCA\INValidator\Exception\ValidationException;

class PdfSupportService
{
    private const PROBE_CODE = <<<'PYTHON'
import json
import platform
data = {
    "pythonVersion": platform.python_version(),
    "pymupdfVersion": None,
}
try:
    import pymupdf
    data["pymupdfVersion"] = pymupdf.pymupdf_version
except Exception:
    pass
print(json.dumps(data))
PYTHON;

    private ValidatorProcessRunner $processRunner;

    public function __construct(
        private ValidatorConfigService $config,
        ?ValidatorProcessRunner $processRunner = null
    ) {
        $this->processRunner = $processRunner ?? new ValidatorProcessRunner(5.0, 0.5, 65536);
    }

    /**
     * @return array{
     *     available: bool,
     *     pythonAvailable: bool,
     *     pythonVersion: ?string,
     *     pymupdfAvailable: bool,
     *     pymupdfVersion: ?string,
     *     reason: ?string
     * }
     */
    public function getStatus(): array
    {
        $python = $this->config->getPythonBinary();

        if (!is_file($python)) {
            return $this->unavailable('Python wurde nicht gefunden.');
        }

        if (!is_executable($python)) {
            return $this->unavailable('Python ist nicht ausführbar.');
        }

        try {
            $result = $this->processRunner->run([
                $python,
                '-I',
                '-s',
                '-c',
                self::PROBE_CODE,
            ]);
        } catch (ValidationException) {
            return $this->unavailable('Python konnte nicht geprüft werden.');
        }

        if ($result['exitCode'] !== 0) {
            return $this->unavailable('Der konfigurierte Python-Interpreter konnte nicht ausgeführt werden.');
        }

        try {
            $data = json_decode(trim($result['stdout']), true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->unavailable('Die Python-Prüfung lieferte keine gültige Antwort.');
        }

        $pythonVersion = is_array($data) && is_string($data['pythonVersion'] ?? null)
            ? $data['pythonVersion']
            : null;
        $pymupdfVersion = is_array($data) && is_string($data['pymupdfVersion'] ?? null)
            ? $data['pymupdfVersion']
            : null;

        if ($pythonVersion === null) {
            return $this->unavailable('Die Python-Version konnte nicht ermittelt werden.');
        }

        if ($pymupdfVersion === null) {
            return $this->unavailable(
                'PyMuPDF ist für dieses Python nicht installiert oder konnte nicht geladen werden.',
                true,
                $pythonVersion
            );
        }

        return [
            'available' => true,
            'pythonAvailable' => true,
            'pythonVersion' => $pythonVersion,
            'pymupdfAvailable' => true,
            'pymupdfVersion' => $pymupdfVersion,
            'reason' => null,
        ];
    }

    /**
     * @return array{
     *     available: false,
     *     pythonAvailable: bool,
     *     pythonVersion: ?string,
     *     pymupdfAvailable: false,
     *     pymupdfVersion: null,
     *     reason: string
     * }
     */
    private function unavailable(
        string $reason,
        bool $pythonAvailable = false,
        ?string $pythonVersion = null
    ): array {
        return [
            'available' => false,
            'pythonAvailable' => $pythonAvailable,
            'pythonVersion' => $pythonVersion,
            'pymupdfAvailable' => false,
            'pymupdfVersion' => null,
            'reason' => $reason,
        ];
    }
}
