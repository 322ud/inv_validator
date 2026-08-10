<?php

declare(strict_types=1);

namespace OCA\KoSITValidator\Service;

class ValidationResult {
    public function __construct(
        private bool $valid,
        private int $exitCode,
        private string $stdout,
        private string $stderr,
        private ?string $reportHtmlPath = null,
        private ?string $reportXmlPath = null,
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

    public function getReportHtmlPath(): ?string {
        return $this->reportHtmlPath;
    }

    public function getReportXmlPath(): ?string {
        return $this->reportXmlPath;
    }

    public function getReportHtml(): ?string {
        return $this->reportHtml;
    }
}
