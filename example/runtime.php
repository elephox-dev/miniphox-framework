<?php
declare(strict_types=1);

use Elephox\Miniphox\MiniphoxBase;
use Elephox\Miniphox\Runner\FrankenPhpRunner;
use Elephox\Miniphox\Runner\RunnerInterface;
use Elephox\Web\Routing\Attribute\Http\Get;

require_once dirname(__DIR__) . '/vendor/autoload.php';

class Frankenphox extends MiniphoxBase implements RunnerInterface {
    use FrankenPhpRunner {
        FrankenPhpRunner::runFrankenPhpServer as run;
    }
}

#[Get('/')]
function index(): string {
    return "Hello!";
}

return Frankenphox::build()
    ->mount('/', index(...))
    ->run();
