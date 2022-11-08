<?php
declare(strict_types=1);

namespace App;

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Elephox\Miniphox\Miniphox;
use Elephox\Web\Routing\Attribute\Http\Post;

class MyDto {
    public function __construct(
        public readonly string $name,
    )
    {
    }
}

#[Post('/greet')]
function greet(MyDto $dto): string
{
    return "Hello, $dto->name!";
}

Miniphox::build()
    ->mount('/', greet(...))
    ->registerDto(MyDto::class)
    ->run();
