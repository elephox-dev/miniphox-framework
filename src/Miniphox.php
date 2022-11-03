<?php
declare(strict_types=1);

namespace Elephox\Miniphox;

use Elephox\DI\Contract\ServiceCollection;
use Elephox\DI\ServiceCollection as ServiceCollectionImpl;
use Elephox\Files\Contract\FileChangedEvent;
use Elephox\Files\File;
use Elephox\Files\FileWatcher;
use Elephox\Logging\EnhancedMessageSink;
use Elephox\Logging\LogLevelProxy;
use Elephox\Logging\SimpleFormatColorSink;
use Elephox\Logging\SingleSinkLogger;
use Elephox\Logging\StandardSink;
use Fig\Http\Message\StatusCodeInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Http\Middleware\LimitConcurrentRequestsMiddleware;
use React\Http\Middleware\RequestBodyParserMiddleware;
use React\Socket\SocketServer;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Stringable;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

class Miniphox implements LoggerAwareInterface
{
    use LogLevelProxy;

    public const DEFAULT_NAMESPACE = 'App';

    public static function build(string $appNamespace = self::DEFAULT_NAMESPACE, ?ServiceCollection $services = null): self
    {
        return new self(self::normalizeAppNamespace($appNamespace), $services);
    }

    protected static function normalizeAppNamespace(string $namespace): string
    {
        return strtolower(trim($namespace, '\\')) . '\\';
    }

    public array $middlewares;
    public readonly ServiceCollection $services;

    protected function __construct(string $appNamespace, ?ServiceCollection $services)
    {
        $this->services = $services ?? new ServiceCollectionImpl();
        $this->services->addSingleton(LoggerInterface::class, SingleSinkLogger::class, fn(): SingleSinkLogger => new SingleSinkLogger(new EnhancedMessageSink(new SimpleFormatColorSink(new StandardSink()))));
        $this->services->addSingleton(Minirouter::class, instance: new Minirouter($appNamespace));

        $this->middlewares = [
            new LimitConcurrentRequestsMiddleware(100),
            new RequestBodyParserMiddleware(),
            new RequestLoggerMiddleware($this->services),
        ];
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->services->addSingleton(LoggerInterface::class, instance: $logger, replace: true);
    }

    protected function getLogger(): LoggerInterface
    {
        return $this->services->requireService(LoggerInterface::class);
    }

    protected function getRouter(): Minirouter
    {
        return $this->services->requireService(Minirouter::class);
    }

    protected function log(mixed $level, Stringable|string $message, array $context = []): void
    {
        $this->getLogger()->log($level, $message, $context);
    }

    public function mount(string $base, iterable $routes): self
    {
        $this->getRouter()->mount($base, $routes, $this->getLogger());

        return $this;
    }

    public function run(string $uri = 'tcp://0.0.0.0:8008'): never
    {
        global $argv;

        if (in_array('--no-watch', $argv, true)) {
            $this->getRouter()->printRoutingTable($this->getLogger());

            $httpUri = str_replace(['tcp', '0.0.0.0'], ['http', 'localhost'], $uri);
            $this->info("Running HTTP server at <blue><underline>$httpUri</underline></blue>");

            $socket = new SocketServer($uri);
            $socket->on('error', fn(Throwable $error) => $this->error($error));

            $http = new HttpServer(...[...$this->middlewares, $this->handle(...)]);
            $http->on('error', fn(Throwable $error) => $this->error($error));
            $http->listen($socket);
        } else {
            $process = null;
            $runPhpServerProcess = function () use (&$process, $argv) {
                $process = new Process([(new PhpExecutableFinder())->find(false), $argv[0], '--no-watch']);

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

                        $this->info($line);
                    }
                });

                $this->info('Server process started', ['pid' => $process->getPid()]);
            };

            $onFileChanged = function (FileChangedEvent $e) use (&$process, $runPhpServerProcess) {
                $this->debug(sprintf("File %s changed. Restarting server...", $e->file()->path()));

                $process?->stop();

                // wait for process to end
                usleep(1000_000);

                $runPhpServerProcess();
            };

            $files = [];

            foreach (['src', 'public'] as $dir) {
                array_push(
                    $files,
                    ...collect(...(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(getcwd() . DIRECTORY_SEPARATOR . $dir))))
                        ->where(fn(SplFileInfo $fileInfo) => $fileInfo->isFile())
                        ->select(fn(SplFileInfo $fileInfo) => new File($fileInfo->getRealPath()))
                        ->toArray(),
                );
            }

            $this->info(sprintf("Watching %d file%s...", count($files), count($files) === 1 ? '' : 's'));

            $watcher = new FileWatcher();
            $watcher->add(
                $onFileChanged,
                ...$files,
            );
            $watcher->poll(false);

            $runPhpServerProcess();

            while ($process->isRunning()) {
                $watcher->poll();

                usleep(1000_000);
            }

            $this->warning(sprintf('Server process exited with code %s', $process->getExitCode() ?? '<unknown>'));
        }

        exit;
    }

    protected function handle(ServerRequestInterface $request): ResponseInterface
    {
        $callback = $this->getRouter()->getHandler($request, $this->services);

        try {
            $result = $callback();

            if (is_string($result)) {
                $response = Response::plaintext($result);
            } else if (is_array($result)) {
                $response = Response::json($result);
            } else if ($result instanceof ResponseInterface) {
                $response = $result;
            } else {
                $this->error(sprintf("Unable to infer response from type %s. Please return a string, array or instance of %s", get_debug_type($result), ResponseInterface::class));

                return $this->handleInternalServerError($request);
            }

            return $response;
        } catch (Throwable $e) {
            $this->error($e);

            return $this->handleInternalServerError($request);
        }
    }

    protected function handleInternalServerError(ServerRequestInterface $request): ResponseInterface
    {
        return Response::plaintext("Unable to handle request.")
            ->withStatus(StatusCodeInterface::STATUS_INTERNAL_SERVER_ERROR);
    }
}
