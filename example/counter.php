<?php
declare(strict_types=1);

// this is the default namespace; can be specified in build()
namespace App;

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Elephox\Miniphox\Miniphox;
use Elephox\Web\Routing\Attribute\Http\Get;
use stdClass;

#[Get('/count')]
function counter(stdClass $counter): string
{
    // $counter will get injected from the service specified below

    return "The current count is $counter->i";
}

$app = Miniphox::build()->mount('/api', counter(...));

// transient services get created every time they are requested (unlike singletons)
$app->getServices()->addTransient(stdClass::class, stdClass::class, function () {
    static $i; // this keeps track of how many times this services was created

    $counter = new stdClass();
    $counter->i = ++$i;

    return $counter;
});

$app->run();
