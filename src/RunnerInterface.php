<?php
declare(strict_types=1);

namespace Elephox\Miniphox;

interface RunnerInterface
{
    public function run(string $host = "0.0.0.0", int $port = 8008): never;
}
