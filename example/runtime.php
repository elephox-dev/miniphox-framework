<?php
declare(strict_types=1);

use Elephox\Miniphox\Miniphox;

require_once dirname(__DIR__) . '/vendor/autoload.php';

return function () {
    return Miniphox::build();
};
