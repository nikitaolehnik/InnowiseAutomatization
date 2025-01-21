<?php

namespace App\Services;

use App\Services\Interfaces\LoggerInterface;

class LoggerService implements LoggerInterface
{
    private readonly mixed $stdoutResource;
    private readonly mixed $stderrResource;

    public function __construct()
    {
        $this->stdoutResource = fopen('php://stdout', 'w');
        $this->stderrResource = fopen('php://stderr', 'w');
    }

    public function log(string $message): self
    {
        fwrite($this->stdoutResource, 'LOG: ' . $message);

        return $this;
    }

    public function error(string $message): self
    {
        fwrite($this->stderrResource, 'ERROR: ' . $message);

        return $this;
    }
}