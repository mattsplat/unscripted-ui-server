<?php

namespace Unscripted\UiServer;

use Error;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Reverb\Events\MessageReceived;

/**
 * Its routes, in the `web` middleware group (sessions, auth, CSRF) unless
 * `unscripted.middleware` says otherwise:
 *
 *   GET  /unscripted/ui.php                        @mtjncn/ui, the same version the server renders with
 *   GET  /unscripted/components/<name>.blade.php   the component, its #[Server] methods kept back
 *   POST /unscripted/call                          {"calls": [{"component", "method", "args"}]}
 *                                                  => {"results": [...]}, in order
 *
 * And a Blade directive, `@unscripted('todos')`: the component rendered on the server (its
 * loaders run first), which the browser takes over once PHP has loaded there.
 *
 * Components are in `unscripted.components`, resources/components by default.
 *
 * With `unscripted.transport` set to 'reverb', calls go over Laravel Reverb's WebSocket instead
 * of POST /unscripted/call: see SocketCalls.
 */
final class UiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Components::class, fn() => new Components(
            config('unscripted.components', resource_path('components')),
            // With the compiled views, so `view:clear` clears them and git ignores them.
            config('view.compiled'),
            fn(string $ability, array $args) => Gate::authorize($ability, $args),
            fn(array $data, array $rules, array $attributes = []) => validator($data, $rules, [], $attributes)->validate(),
        ));
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'unscripted');

        if ($this->app->runningInConsole()) {
            $this->commands([ConvertCommand::class]);
        }

        // A whole page for a component: Route::unscripted('/todos', 'todos', ['title' => 'Todos']).
        // `layout` names your own view instead, which gets the component as $slot (and $title)
        // and must load ui.php and the runtime itself; other options go to @unscripted.
        Route::macro('unscripted', function (string $uri, string $component, array $options = []) {
            $layout = $options['layout'] ?? 'unscripted::page';
            $title = $options['title'] ?? ucfirst(str_replace(['-', '_'], ' ', $component));
            unset($options['layout'], $options['title']);
            return Route::get($uri, fn() => view($layout, [
                'slot' => new \Illuminate\Support\HtmlString(UiServiceProvider::tag($component, $options)), // not self: macros run as the router
                'title' => $title,
            ]));
        });

        // With no (...) it's text, such as "@mtjncn/ui", and stays as written.
        Blade::directive('unscripted', fn(string $arguments) => $arguments === '' ? '@unscripted'
            : "<?php echo \\Unscripted\\UiServer\\UiServiceProvider::tag($arguments); ?>");

        if (self::transport() === 'reverb') {
            if (!class_exists(MessageReceived::class)) {
                throw new Error("unscripted.transport is 'reverb', but laravel/reverb isn't installed");
            }
            // Each user's channel, which the browser's socket joins so its calls run as them.
            Broadcast::channel('unscripted.{id}', fn($user, string $id) => (string) $user->getAuthIdentifier() === $id);
            Event::listen(MessageReceived::class, [SocketCalls::class, 'handle']);
        }

        Route::middleware(config('unscripted.middleware', ['web']))->prefix('unscripted')->group(function () {
            Route::get('ui.php', fn() => response()->file(__DIR__ . '/ui.php', [
                'Content-Type' => 'text/plain; charset=utf-8',
                'X-Content-Type-Options' => 'nosniff',
            ]));

            Route::get('components/{name}.blade.php', function (string $name, Components $components) {
                $source = $components->browserSource($name) ?? abort(404, "there's no component $name");
                return response($source, 200, [
                    'Content-Type' => 'text/plain; charset=utf-8',
                    'X-Content-Type-Options' => 'nosniff',
                    'Cache-Control' => 'no-cache',
                ]);
            })->where('name', '[A-Za-z0-9_-]+');

            // A u-model file input's file, kept until a #[Server] method takes it (Upload).
            Route::post('upload', function (Request $request) {
                $request->validate(['file' => config('unscripted.upload_rules', 'required|file|max:10240')]);
                return response()->json(Upload::keep($request->file('file')));
            });
            Route::get('upload/{id}', function (string $id) {
                $upload = Upload::find($id) ?? abort(404);
                return response()->file($upload->path(), ['Content-Type' => $upload->type, 'Content-Disposition' => 'inline']);
            })->where('id', '[a-f0-9]{40}');

            Route::post('call', function (Request $request, Components $components) {
                // The body as sent: the request's own json() has been through middleware such
                // as ConvertEmptyStringsToNull, which would turn an argument '' into null.
                $body = json_decode($request->getContent(), true);
                try {
                    if (($body['stream'] ?? false) === true) {
                        return self::stream($components, $body['calls'] ?? null);
                    }
                    $results = $components->run($body['calls'] ?? null);
                } catch (UnknownCall $e) {
                    abort(404, $e->getMessage());
                } catch (BadArguments $e) {
                    abort(422, $e->getMessage());
                }
                return response()->json(['results' => $results]);
            });
        });
    }

    private static function transport(): string
    {
        $transport = config('unscripted.transport', 'http');
        return in_array($transport, ['http', 'reverb'], true)
            ? $transport
            : throw new Error("unscripted.transport is '$transport': it's 'http' or 'reverb'");
    }

    /**
     * Where the page's socket goes, for @unscripted to tell the browser, or null over HTTP:
     * Reverb's address (`unscripted.socket_url`, else from the `reverb` broadcasting
     * connection), the user's channel (none for a guest) and the URL that signs it.
     */
    public static function socket(): ?array
    {
        if (self::transport() !== 'reverb') {
            return null;
        }
        $reverb = config('broadcasting.connections.reverb');
        $options = $reverb['options'] ?? [];
        $scheme = ($options['scheme'] ?? 'https') === 'https' ? 'wss' : 'ws';
        $id = auth()->id();
        return [
            'url' => config('unscripted.socket_url')
                ?? "$scheme://{$options['host']}:{$options['port']}/app/{$reverb['key']}?protocol=7&client=unscripted&version=0.1",
            'channel' => $id === null ? null : "private-unscripted.$id",
            'auth' => url('broadcasting/auth'),
        ];
    }

    /** @unscripted('name', [options]): see Components::tag() for the options. */
    public static function tag(string $name, array $options = []): string
    {
        return app(Components::class)->tag($name, url("unscripted/components/$name.blade.php"), self::socket(), [
            'query' => request()->query(),
            'endpoint' => url('unscripted/call'),
            ...$options,
        ]);
    }

    /**
     * One call, streamed (`->into()` in the browser): a JSON line per chunk the method yields,
     * `{"chunk": ...}`, then `{"result": ...}` (its return value, or the chunks joined), or
     * `{"error": {status, message, errors}}` if it throws once it's begun. A method that isn't a
     * generator just gives its result.
     */
    private static function stream(Components $components, mixed $calls): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        if (!is_array($calls) || count($calls) !== 1 || !is_string($calls[0]['component'] ?? null)
            || !is_string($calls[0]['method'] ?? null) || !is_array($calls[0]['args'] ?? null)) {
            throw new BadArguments('a stream is one call: a component, a method and a list of args');
        }
        $result = $components->call($calls[0]['component'], $calls[0]['method'], array_values($calls[0]['args']), drain: false);
        return response()->stream(function () use ($result) {
            $line = function (array $data) {
                echo json_encode($data, JSON_PARTIAL_OUTPUT_ON_ERROR), "\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            };
            if (!$result instanceof \Generator) {
                $line(['result' => $result]);
                return;
            }
            try {
                $text = '';
                foreach ($result as $chunk) {
                    $text .= $chunk;
                    $line(['chunk' => (string) $chunk]);
                }
                $line(['result' => $result->getReturn() ?? $text]);
            } catch (\Throwable $e) {
                $failure = SocketCalls::failure($e);
                if ($failure === null) {
                    report($e);
                    $failure = ['status' => 500, 'message' => config('app.debug') ? $e->getMessage() : 'Server Error'];
                }
                $line(['error' => $failure]);
            }
        }, 200, ['Content-Type' => 'application/x-ndjson', 'Cache-Control' => 'no-cache', 'X-Accel-Buffering' => 'no']);
    }
}
