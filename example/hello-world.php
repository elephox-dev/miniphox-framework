<?php
declare(strict_types=1);

// this is the default namespace; can be specified in build()
namespace App;

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Elephox\Miniphox\Attributes\Get;
use Elephox\Miniphox\Miniphox;

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

// index() and greet() are mounted at '/api'.
// This maps '/api' -> index() and '/api/greet/[name]' -> greet() according to their attributes above.
//
// You can pass first-class-callables or just the method name to the mount method.
Miniphox::build()->mount('/api', [index(...), 'greet'])->run();
