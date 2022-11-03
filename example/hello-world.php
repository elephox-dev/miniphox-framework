<?php
declare(strict_types=1);

// this is the default namespace; can be specified in build()
namespace App;

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Elephox\Miniphox\Miniphox;
use Elephox\Web\Routing\Attribute\Http\Get;

#[Get] // defaults to '/'
function index(): string
{
    return "Hello, World!";
}

#[Get('/greet/[name]')] // using route params
function greet(string $name): string
{
    return "Hello, $name!";
}

// This creates a new Miniphox app.
Miniphox::build()
    // index() and greet() are mounted at '/api'.
    // This maps '/api' -> index() and '/api/greet/[name]' -> greet() according to their attributes above.
    //
    // You can pass first-class-callables or just the method name to the mount method.
    ->mount('/api', index(...), 'greet')

    // This will start the HTTP server on http://0.0.0.0:8008.
    // Pass a string uri to bind to a specific ip address or a different port.
    ->run();
