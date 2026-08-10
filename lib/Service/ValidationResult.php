<?php

declare(strict_types=1);

namespace OCA\KoSITValidator\Service;

class ValidationResult {
    public function __construct(
        private bool $valid,
        private int $exitCode,
        private string $stdout,
        private string $stderr,
        private ?string $reportHtml = null
    ) {}

    public function isValid(): bool {
        return $this->valid;
    }

    public function getExitCode(): int {
        return $this->exitCode;
    }

    public function getStdout(): string {
        return $this->stdout;
    }

    public function getStderr(): string {
        return $this->stderr;
    }

    public function getReportHtml(): ?string {
        return $this->reportHtml;
    }
}
