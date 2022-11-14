<?php
declare(strict_types=1);

namespace Elephox\Miniphox;

use Elephox\DI\Contract\ServiceCollection;
use Symfony\Component\Process\PhpExecutableFinder;

class Miniphox extends MiniphoxBase implements RunnerInterface
{
    use ReactPhpRunner {
        ReactPhpRunner::run as runServerProcess;
    }

    use FileWatchingRunner {
        FileWatchingRunner::__construct as constructFileWatcher;
        FileWatchingRunner::run as runWatcherProcess;
    }

    protected string $host = "0.0.0.0";
    protected int $port = 8008;

    protected function __construct(string $appNamespace, ?ServiceCollection $services)
    {
        parent::__construct($appNamespace, $services);

        $this->constructFileWatcher();
    }

    public function setHost(string $host): self
    {
        $this->host = $host;

        return $this;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function setPort(int $port): self
    {
        $this->port = $port;

        return $this;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function run(): int
    {
        if (!$this->shouldWatch()) {
            $this->getRouter()->printRoutingTable($this->getLogger());

            $httpHost = $this->getHost() === '0.0.0.0' ? 'localhost' : $this->getHost();
            $httpPort = $this->getPort() === 80 ? '' : ":{$this->getPort()}";
            $this->getLogger()->info("Running HTTP server at <blue><underline>http://$httpHost$httpPort</underline></blue>");

            $this->runServerProcess();

            exit;
        }

        $this->runWatcherProcess();

        return 0;
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
