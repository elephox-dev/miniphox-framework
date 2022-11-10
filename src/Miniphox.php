<?php
declare(strict_types=1);

namespace Elephox\Miniphox;

use Elephox\DI\Contract\ServiceCollection;
use Symfony\Component\Process\PhpExecutableFinder;

class Miniphox extends AbstractMiniphox implements RunnerInterface
{
    use ReactPhpRunner {
        ReactPhpRunner::run as runServerProcess;
    }
    use FileWatchingRunner {
        FileWatchingRunner::__construct as constructFileWatcher;
        FileWatchingRunner::run as runWatcherProcess;
    }

    public const DEFAULT_NAMESPACE = 'App';

    public static function build(string $appNamespace = self::DEFAULT_NAMESPACE, ?ServiceCollection $services = null): self
    {
        return new self($appNamespace, $services);
    }

    protected function __construct(string $appNamespace, ?ServiceCollection $services)
    {
        parent::__construct($appNamespace, $services);

        $this->constructFileWatcher();
    }

    public function run(string $host = "0.0.0.0", int $port = 8008): never
    {
        if (!$this->shouldWatch()) {
            $this->runServerProcess($host, $port);
        }

        $this->runWatcherProcess($host, $port);
    }

    private function shouldWatch(): bool {
        global $argv;

        if (in_array('--no-watch', $argv, true)) {
            return false;
        }

        return $this->watchedNodes->isNotEmpty();
    }

    public function getNoWatchCommandLine(): array
    {
        global $argv;

        return [(new PhpExecutableFinder())->find(false), ...$argv, '--no-watch'];
    }
}
