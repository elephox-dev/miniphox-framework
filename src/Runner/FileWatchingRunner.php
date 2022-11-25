<?php
declare(strict_types=1);

namespace Elephox\Miniphox\Runner;

use Elephox\Collection\ArraySet;
use Elephox\Collection\DefaultEqualityComparer;
use Elephox\Files\Contract\Directory as DirectoryContract;
use Elephox\Files\Contract\File as FileContract;
use Elephox\Files\Contract\FileChangedEvent;
use Elephox\Files\Directory;
use Elephox\Files\File;
use Elephox\Files\FileWatcher;
use Elephox\Files\Link;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

trait FileWatchingRunner
{
    private readonly ArraySet $watchedNodes;

    public function __construct()
    {
        $this->watchedNodes = new ArraySet(comparer: function (mixed $a, mixed $b): bool {
            if (is_string($a) && is_string($b)) {
                return DefaultEqualityComparer::equalsIgnoreCase($a, $b);
            }

            return DefaultEqualityComparer::same($a, $b);
        });
    }

    abstract protected function getLogger(): LoggerInterface;

    abstract protected function runServerProcess(): int;

    public function runFileWatcher(): int
    {
        if (!$this->shouldWatch()) {
            $this->getRouter()->printRoutingTable($this->getLogger());

            $httpHost = $this->getHost() === '0.0.0.0' ? 'localhost' : $this->getHost();
            $httpPort = $this->getPort() === 80 ? '' : ":{$this->getPort()}";
            $this->getLogger()->info("Running HTTP server at <blue><underline>http://$httpHost$httpPort</underline></blue>");

            return $this->runServerProcess();
        }

        return $this->runWatcherProcess();
    }

    protected function shouldWatch(): bool {
        global $argv;

        if (in_array('--no-watch', $argv, true)) {
            return false;
        }

        return $this->watchedNodes->isNotEmpty();
    }

    protected function getNoWatchCommandLine(): array
    {
        global $argv;

        return [(new PhpExecutableFinder())->find(false), ...$argv, '--no-watch'];
    }

    public function watch(string|FileContract|DirectoryContract ...$nodes): self {
        foreach ($nodes as $node) {
            $this->watchedNodes->add($node);
        }

        return $this;
    }

    private function runWatcherProcess(): int
    {
        $process = null;
        $runPhpServerProcess = function () use (&$process) {
            $process = new Process($this->getNoWatchCommandLine());

            /** @psalm-suppress UnusedClosureParam */
            $process->start(function (string $type, string $buffer): void {
                $buffer = trim($buffer);
                foreach (explode("\n", $buffer) as $line) {
                    // timestamp
                    if ($closingBracketPos = strpos($line, ']')) {
                        $line = substr($line, $closingBracketPos + 2);
                    }

                    // log level
                    if ($closingBracketPos = strpos($line, ']')) {
                        $line = substr($line, $closingBracketPos + 2);
                    }

                    $this->getLogger()->info($line);
                }
            });

            $this->getLogger()->info('Server process started', ['pid' => $process->getPid()]);
        };

        $onFileChanged = function (FileChangedEvent $e) use (&$process, $runPhpServerProcess) {
            $this->getLogger()->debug(sprintf("File %s changed. Restarting server...", $e->file()->path()));

            $process?->stop();

            // wait for process to end
            usleep(1000_000);

            $runPhpServerProcess();
        };

        $watchedFiles = $this->watchedNodes->selectMany(function (mixed $element): iterable {
            if (is_string($element)) {
                if (!file_exists($element)) {
                    $this->getLogger()->warning("Cannot watch $element as it doesn't exist or isn't accessible");

                    return [];
                }

                if (is_file($element)) {
                    $element = new File($element);
                } else if (is_dir($element)) {
                    $element = new Directory($element);
                } else if (is_link($element)) {
                    $element = new Link($element);
                }
            }

            if ($element instanceof Link) {
                $element = $element->target();
            }

            if ($element instanceof FileContract) {
                return [$element];
            }

            if ($element instanceof DirectoryContract) {
                return $element->recurseFiles();
            }

            $this->getLogger()->warning(sprintf("Invalid value provided to watch(): %s", get_debug_type($element)));

            return [];
        })->toList();

        $this->getLogger()->info(sprintf("Watching %d file%s...", count($watchedFiles), count($watchedFiles) === 1 ? '' : 's'));

        $watcher = new FileWatcher();
        $watcher->add(
            $onFileChanged,
            ...$watchedFiles,
        );
        $watcher->poll(false);

        $runPhpServerProcess();

        while ($process->isRunning()) {
            $watcher->poll();

            // TODO: re-create watcher if new files got added (requires directory watcher instead of file watcher)

            usleep(1000_000);
        }

        $this->getLogger()->warning(sprintf('Server process exited with code %s', $process->getExitCode() ?? '<unknown>'));

        return $process->getExitCode() ?? 0;
    }
}
