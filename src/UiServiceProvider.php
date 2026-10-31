<?php

namespace Unscripted\UiServer;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Two routes, in the `web` middleware group (sessions, auth, CSRF) unless
 * `unscripted.middleware` says otherwise:
 *
 *   GET  /unscripted/components/<name>.blade.php   the component, its #[Server] methods kept back
 *   POST /unscripted/call                          {"calls": [{"component", "method", "args"}]}
 *                                                  => {"results": [...]}, in order
 *
 * Components are in `unscripted.components`, resources/components by default.
 */
final class UiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Components::class, fn() => new Components(
            config('unscripted.components', resource_path('components')),
        ));
    }

    public function boot(): void
    {
        Route::middleware(config('unscripted.middleware', ['web']))->prefix('unscripted')->group(function () {
            Route::get('components/{name}.blade.php', function (string $name, Components $components) {
                $source = $components->browserSource($name) ?? abort(404, "there's no component $name");
                return response($source, 200, [
                    'Content-Type' => 'text/plain; charset=utf-8',
                    'X-Content-Type-Options' => 'nosniff',
                    'Cache-Control' => 'no-cache',
                ]);
            })->where('name', '[A-Za-z0-9_-]+');

            Route::post('call', function (Request $request, Components $components) {
                // The body as sent: the request's own json() has been through middleware such
                // as ConvertEmptyStringsToNull, which would turn an argument '' into null.
                $calls = json_decode($request->getContent(), true)['calls'] ?? null;
                if (!is_array($calls) || !array_is_list($calls) || $calls === [] || count($calls) > 20) {
                    abort(422, 'expected {"calls": [...]}, 1 to 20 of them');
                }
                $results = [];
                foreach ($calls as $call) {
                    if (!is_string($call['component'] ?? null) || !is_string($call['method'] ?? null)
                        || !is_array($call['args'] ?? null) || !array_is_list($call['args'])) {
                        abort(422, 'each call needs a component, a method and a list of args');
                    }
                    try {
                        $results[] = $components->call($call['component'], $call['method'], $call['args']);
                    } catch (UnknownCall $e) {
                        abort(404, $e->getMessage());
                    } catch (BadArguments $e) {
                        abort(422, $e->getMessage());
                    }
                }
                return response()->json(['results' => $results]);
            });
        });
    }
}
