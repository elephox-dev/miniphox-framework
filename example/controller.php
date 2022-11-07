<?php
declare(strict_types=1);

namespace App;

use Elephox\Miniphox\Miniphox;
use Elephox\Web\Routing\Attribute\Http\Get;
use Psr\Log\LoggerInterface;

require_once dirname(__DIR__) . '/vendor/autoload.php';

class MyController {
    public function __construct(
        private readonly LoggerInterface $logger,
    )
    {
    }

    #[Get]
    public function index(): string {
        return "Hello, World";
    }

    #[Get('/[name]')]
    public function greet(string $name): string {
        $this->logger->info("Greeting $name");

        return "Hello, $name";
    }
}

Miniphox::build()
    ->mountController('/greet', MyController::class)
    ->run();
