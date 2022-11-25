Changelog
=========

Version 0.7.0
-------------

* Refactored runner traits into their own namespace
* Refactored middlewares into their own namespace
* Current request is now added as a service and removed afterwards

Version 0.6.0
-------------

* Moved middlewares to ReactPhpRunner for cleaner separation of concerns
* Added StaticFileServerMiddleware to handle static file requests
* `handleInternalServerError` now receives the thrown exception, if any

Version 0.5.9
-------------

* Added request hooks you can override to modify the request or response while it is processed and to stuff after the response is sent
* Made `Minirouter` implement `LoggerAwareInterface` so it can be used more versatile
* Improved support for enhanced sinks in `RequestLoggerMiddleware`
* Added handler for unprocessable entities (mostly DTOs where some parameters cannot be resolved) in `Minirouter`
* Moved `build` back to `MiniphoxBase` so inheritors can use it

Version 0.5.8
-------------

* Expose `GenericSet` for middlewares in `ReactPhpRunner` trait

Version 0.5.7
-------------

* Expose source array set for middlewares

Version 0.5.6
-------------

* Split runner methods into traits and provide default `Miniphox` class
* Move logging to `Miniphox`
* Added experimental `FrankenPhpRunner`

Version 0.5.5
-------------

* Also handle exceptions while resolving the route handler

Version 0.5.4
-------------

* Don't register constructors or destructors

Version 0.5.3
-------------

* Added ability to register DTOs, which can be filled using request bodies
* Added RequestJsonBodyParserMiddleware

Version 0.5.2
-------------

* Added ability to mount controllers

Version 0.5.1
-------------

* Pass all args to process
* Initialize routeMap with methods key for index-level routes

Version 0.5.0
-------------

* Changed API for mount and watch and refactored file collection method

Version 0.4.4
-------------

* Turn watchable files collector into local function and added TODO for better file watching

Version 0.4.3
-------------

* Added file watching
* Updated dependencies

Version 0.4.2
-------------

* Updated usage of Console::table

Version 0.4.1
-------------

* Updated dependencies

Version 0.4.0
-------------

* Use Elephox HTTP attributes
* Extracted router logic into own class
* Updated docs and dependencies

Version 0.3.0
-------------

* Rename project to elephox/miniphox-framework
* Pin elephox/framework to 0.7

Version 0.2.0
-------------

* Redirect to sleep endpoint instead of 404
* Decluttered Miniphox and moved logging to middleware

Version 0.1.0
-------------

* Initial release