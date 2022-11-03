<?php
declare(strict_types=1);

namespace App;

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Elephox\Miniphox\Miniphox;
use Elephox\Web\Routing\Attribute\Http\Get;
use Fig\Http\Message\StatusCodeInterface;
use React\Http\Message\Response;

#[Get]
function index(): string {
    return "This file is being watched. The server automatically restarts if it is changed. Try it!";
}

#[Get('/redirect')]
function redirect(): Response
{
    return new Response(StatusCodeInterface::STATUS_TEMPORARY_REDIRECT, ['Location' => '/api/sleep']);
}

#[Get('/sleep')]
function sleep(): string
{
    usleep(1_000_000);

    return "What a good sleep. Look at the timing in your network tab!";
}

Miniphox::build()
    ->mount('/', index(...))
    ->mount('/api', redirect(...), sleep(...))
    ->watch(__FILE__)
    ->run();
