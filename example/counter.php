<?php
declare(strict_types=1);

// this is the default namespace; can be specified in build()
namespace App;

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Elephox\Miniphox\Miniphox;
use Elephox\Web\Routing\Attribute\Http\Get;
use stdClass;

// suppose we have these services:

class TransientCounter {
    public int $value = 0;
}

class SingletonCounter {
    public int $value = 0;
}

// and this endpoint:

#[Get('/count')]
function count(TransientCounter $transientCounter, SingletonCounter $singletonCounter): array
{
    // The counters will get injected from the services specified below.

    // Value will only increase above 1 on the singleton since the transient counter is re-created every time it is
    // requested.
    $transientCounter->value++;
    $singletonCounter->value++;

    return [
        'transient' => $transientCounter->value,
        'singleton' => $singletonCounter->value,
    ];
}

// build the app and register our services
$app = Miniphox::build()->mount('/api', count(...));

// transient services get created every time they are requested (unlike singletons)
$app->getServices()->addTransient(TransientCounter::class, TransientCounter::class, fn() => new TransientCounter());

// singleton services are only created once and are then kept in memory for repeated use of the same instance
$app->getServices()->addSingleton(SingletonCounter::class, SingletonCounter::class, fn() => new SingletonCounter());

$app->run();
