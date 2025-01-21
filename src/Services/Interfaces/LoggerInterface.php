<?php

namespace App\Services\Interfaces;

interface LoggerInterface
{
    public function log(string $message): self;
    public function error(string $message): self;
}