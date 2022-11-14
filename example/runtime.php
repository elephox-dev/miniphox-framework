<?php
declare(strict_types=1);

use Elephox\Miniphox\FrankenPhpRunner;
use Elephox\Miniphox\MiniphoxBase;
use Elephox\Miniphox\RunnerInterface;

require_once dirname(__DIR__) . '/vendor/autoload.php';

class Frankenphox extends MiniphoxBase implements RunnerInterface {
    use FrankenPhpRunner;
}

return Frankenphox::build()->run();
