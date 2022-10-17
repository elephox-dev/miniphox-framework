<?php
declare(strict_types=1);

namespace Elephox\Miniphox\Attributes;

abstract class HttpMethodAttribute
{
    public function __construct(
        public readonly string $verb,
        public readonly string $path,
    )
    {
    }
}
