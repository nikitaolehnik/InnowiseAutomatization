<?php

namespace App\Application\Events\Interfaces;

interface EventInterface
{
    public function handle(array $event): void;
}