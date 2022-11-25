<?php
declare(strict_types=1);

namespace Elephox\Miniphox;

use Elephox\DI\Contract\ServiceCollection;
use Elephox\Miniphox\Runner\FileWatchingRunner;
use Elephox\Miniphox\Runner\ReactPhpRunner;
use Elephox\Miniphox\Runner\RunnerInterface;

class Miniphox extends MiniphoxBase implements RunnerInterface
{
    use ReactPhpRunner {
        ReactPhpRunner::__construct as constructReactPhpServer;
        ReactPhpRunner::runReactServer as runServerProcess;
    }

    use FileWatchingRunner {
        FileWatchingRunner::__construct as constructFileWatcher;
        FileWatchingRunner::runFileWatcher as run;
    }

    protected function __construct(string $routesNamespace, ?ServiceCollection $services)
    {
        parent::__construct($routesNamespace, $services);

        $this->constructFileWatcher();
        $this->constructReactPhpServer();
    }
}
