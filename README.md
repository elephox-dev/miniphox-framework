# Miniphox

TL;DR:

```bash
composer create-project elephox/miniphox my-api && \
cd my-api && \
composer run serve
```

Then:

```bash
curl http://localhost:8008/api/greet/$(whoami)
```

Done.

### Miniphox explained as fast as possible

- [React HTTP server] backbone (multi-threaded PHP socket server)
- [Elephox/DI] dependency injection
- Approachable router using [PHP Attributes]
- Best for (small) API projects
- Fast to production with minimal effort

### Show me some code already

`hello-world.php`
```php
<?php
declare(strict_types=1);

// this is the default namespace; can be specified in build()
namespace App;

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Elephox\Miniphox\Attributes\Get;
use Elephox\Miniphox\Miniphox;

#[Get] // defaults to '/'
function index(): string {
    return "Hello, World!";
}

#[Get('/greet/[name]')] // using route params
function greet(string $name): string {
    return "Hello, $name!";
}

// This creates a new Miniphox app.
Miniphox::build()

    // index() and greet() are mounted at '/api'.
    // This maps '/api' -> index() and '/api/greet/[name]' -> greet() according to their attributes above.
    //
    // You can pass first-class-callables or just the method name to the mount method.
    ->mount('/api', [index(...), 'greet'])

    // This will start the HTTP server on http://0.0.0.0:8008.
    // Pass a string uri to bind to a specific ip address or a different port.
    ->run();
```

The example above is the simplest way to use Miniphox.
Just some straightforward route handlers with little to no logic and one line to run everything.

A more sophisticated example might use dependency injection to inject a database connection or logger into the route handler:

`counter.php`

```php
<?php // [...]

#[Get('/count')]
function counter(stdClass $counter): string {
    // $counter will get injected from the service specified below

    return "The current count is $counter->i";
}

$app = Miniphox::build()->mount('/api', [counter(...)]);

// transient services get created every time they are requested (unlike singletons)
$app->services->addTransient(stdClass::class, stdClass::class, function () {
    static $i; // this keeps track of how many times this services was created

    $counter = new stdClass();
    $counter->i = ++$i;

    return $counter;
});

$app->run();
```

You can find both of these examples in the folder [`example`](example).
Along with them is a `showcase.php` file, which contains some advanced examples.

### The ugly part

This project is still in its infant state, and it might stay that way.
There are some things left to do, namely improving the routers capabilities, and I don't know when or how much time I'm going to invest going forward.
It is a toy project after all.

Some things left TODO include:

- [ ] check if multiple dynamic routes exist and determine best fit
- [ ] regex patterns in route parameters
- [ ] check how to improve performance even further (is it possible to leverage opcache?)
- [ ] write up some tutorials on how to set up Doctrine and other common software stacks (phpdotenv using [Elephox/Configuration]?)
- [x] ~~Improve the way server timings are logged (maybe also look into how to send headers while processing the request with ReactPHP)~~
- [ ] _MAYBE_: refactor Miniphox to be server-agnostic (exchange ReactPHP with other servers like OpenSwoole, Amphp?)

[React HTTP server]: https://reactphp.org/http
[Elephox/DI]: https://packagist.org/packages/elephox/di
[Elephox/Configuration]: https://packagist.org/packages/elephox/configuration
[PHP Attributes]: https://stitcher.io/blog/attributes-in-php-8
