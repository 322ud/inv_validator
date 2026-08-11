<?php

declare(strict_types=1);

namespace OCA\KoSITValidator\Service;

use OCA\KoSITValidator\Exception\ValidationException;

final class ValidatorProcessRunner {
    public function __construct(
        private float $timeoutSeconds = 60.0,
        private float $terminationGraceSeconds = 2.0,
        private int $maxOutputBytes = 1024 * 1024
    ) {}

    /**
     * @param list<string> $command
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    public function run(array $command): array {
        $process = proc_open(
            $command,
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );

        if (!is_resource($process)) {
            throw new ValidationException('Validator process could not be started');
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $start = microtime(true);
        $lastStatus = null;

        while (true) {
            $this->appendOutput($stdout, stream_get_contents($pipes[1]));
            $this->appendOutput($stderr, stream_get_contents($pipes[2]));

            $status = proc_get_status($process);
            $lastStatus = $status;

            if (!$status['running']) {
                break;
            }

            if ((microtime(true) - $start) >= $this->timeoutSeconds) {
                $this->terminate($process, $pipes);
                throw new ValidationException('Validator process timed out');
            }

            usleep(100000);
        }

        $this->appendOutput($stdout, stream_get_contents($pipes[1]));
        $this->appendOutput($stderr, stream_get_contents($pipes[2]));

        fclose($pipes[1]);
        fclose($pipes[2]);

        $closeExitCode = proc_close($process);
        $statusExitCode = is_array($lastStatus) ? $lastStatus['exitcode'] : -1;

        return [
            'exitCode' => $statusExitCode >= 0 ? $statusExitCode : $closeExitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }

    /**
     * @param resource $process
     * @param array<int, resource> $pipes
     */
    private function terminate($process, array $pipes): void {
        proc_terminate($process);

        $graceDeadline = microtime(true) + $this->terminationGraceSeconds;
        do {
            usleep(10000);
            $status = proc_get_status($process);
        } while ($status['running'] && microtime(true) < $graceDeadline);

        if ($status['running']) {
            proc_terminate($process, 9);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
    }

    private function appendOutput(string &$target, string|false $chunk): void {
        if ($chunk === false || $chunk === '') {
            return;
        }

        $remaining = $this->maxOutputBytes - strlen($target);

        if ($remaining > 0) {
            $target .= substr($chunk, 0, $remaining);
        }
    }
}
