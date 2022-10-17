<?php
declare(strict_types=1);

namespace App;

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Elephox\Miniphox\Attributes\Get;
use Elephox\Miniphox\Miniphox;
use Fig\Http\Message\StatusCodeInterface;
use Psr\Http\Message\ResponseInterface;
use React\Http\Message\Response;
use stdClass;

#[Get('/redirect')]
function redirect(): Response
{
    return new Response(StatusCodeInterface::STATUS_TEMPORARY_REDIRECT, ['Location' => '/']);
}

#[Get('/sleep')]
function sleep(): string
{
    usleep(1_000_000);

    return "What a good sleep. Look at the timing in your network tab!";
}

Miniphox::build()->mount('/api', [redirect(...), sleep(...)])->run();
