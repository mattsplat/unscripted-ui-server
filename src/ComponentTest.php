<?php

namespace Unscripted\UiServer;

use Closure;
use Throwable;
use Unscripted\Ui\Component;
use Unscripted\Ui\ServerError;

/**
 * A component driven without a browser, for tests: its loaders, actions and server calls run
 * here, the calls made directly (as POST /unscripted/call would, `can` and #[Validate]
 * included, but no middleware). From `app(Components::class)->test('todos')`:
 *
 *   ->set('draft', 'Milk')->call('add')->assertSee('Milk')->assertSet('draft', '')
 *   ->call('add')->assertHasErrors('title')
 *
 * Assertions use PHPUnit's when it's there (so they count), and throw AssertionError when not.
 * A failed call becomes a ServerError in the component, as in a browser; one the component
 * doesn't handle (its failed() throws it, to the console in a browser) is kept for
 * assertFailed(). An exception that's a bug (a 500 there) is thrown here, so the test sees it.
 */
final class ComponentTest
{
    private ?ServerError $failure = null;

    public function __construct(private Components $components, private string $name, private Component $component, array $query = [])
    {
        Component::$dispatched = [];
        Component::$redirected = null;
        $this->use();
        $server = fn(string $method, array $args) => $this->components->call($this->name, $method, $args);
        $this->in(fn() => $this->prerender($server, $query));
    }

    /** Sets `$path` as u-model would (hooks, and the field checked); other properties directly. */
    public function set(string $path, mixed $value): self
    {
        $this->use();
        $this->in(function () use ($path, $value) {
            try {
                $this->write($path, $value);
            } catch (\Error) {
                $this->assign($path, $value); // not a #[State] path: a plain property
            }
            $this->refresh();
        });
        return $this;
    }

    /** Runs action (or #[Server] method) `$action` as the page would, then the loaders. */
    public function call(string $action, mixed ...$args): self
    {
        $this->use();
        $expression = $action . '(' . substr(json_encode($args, JSON_THROW_ON_ERROR), 1, -1) . ')';
        $this->failure = null;
        try {
            $this->in(function () use ($expression) {
                $this->call($expression);
                $this->refresh();
            });
        } catch (ServerError $e) {
            $this->failure = $e;
        }
        return $this;
    }

    /** That the last call() failed and the component didn't handle it: with `$status`, if given. */
    public function assertFailed(?int $status = null): self
    {
        $failure = $this->failure;
        return $this->assert($failure !== null && ($status === null || $failure->status === $status),
            $failure === null ? "the last call didn't fail" : "it failed with {$failure->status}, not $status");
    }

    public function get(string $path): mixed
    {
        $value = $this->component;
        foreach (explode('.', $path) as $key) {
            $value = is_object($value) ? $value->{$key} : ($value[$key] ?? null);
        }
        return $value;
    }

    public function html(): string
    {
        return $this->in(fn() => $this->html());
    }

    public function component(): Component
    {
        return $this->component;
    }

    public function assertSee(string $text, bool $escape = true): self
    {
        $text = $escape ? htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5) : $text;
        return $this->assert(str_contains($this->html(), $text), "the HTML doesn't have \"$text\"");
    }

    public function assertDontSee(string $text, bool $escape = true): self
    {
        $text = $escape ? htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5) : $text;
        return $this->assert(!str_contains($this->html(), $text), "the HTML has \"$text\"");
    }

    public function assertSet(string $path, mixed $value): self
    {
        $actual = $this->get($path);
        return $this->assert($actual === $value, "$path is " . var_export($actual, true) . ', not ' . var_export($value, true));
    }

    /** That there are errors: any, or for each of `$keys`. */
    public function assertHasErrors(string|array|null $keys = null): self
    {
        $errors = $this->component->errors();
        if ($keys === null) {
            return $this->assert($errors->any(), 'there are no errors');
        }
        foreach ((array) $keys as $key) {
            $this->assert($errors->has($key), "there's no error for $key (there are: " . implode(' ', $errors->all()) . ')');
        }
        return $this;
    }

    public function assertHasNoErrors(): self
    {
        return $this->assert(!$this->component->errors()->any(), 'there are errors: ' . implode(' ', $this->component->errors()->all()));
    }

    public function assertDispatched(string $event, mixed $detail = null): self
    {
        foreach (Component::$dispatched as [$name, $sent]) {
            if ($name === $event && ($detail === null || $sent === $detail)) {
                return $this->assert(true, '');
            }
        }
        return $this->assert(false, "$event wasn't dispatched" . ($detail === null ? '' : ' with that detail'));
    }

    public function assertRedirect(?string $url = null): self
    {
        $to = Component::$redirected;
        return $this->assert($to !== null && ($url === null || $to === $url), $to === null ? "it didn't redirect" : "it redirected to $to");
    }

    /** This component's calls go to this test's server. */
    private function use(): void
    {
        Component::$transport = function (array $calls, Closure $done) {
            try {
                $results = $this->components->run($calls);
            } catch (Throwable $e) {
                $failure = SocketCalls::failure($e) ?? throw $e;
                $done(null, new ServerError($failure['message'], $failure['status'], $failure['errors'] ?? []));
                return;
            }
            $done($results, null);
        };
    }

    /** Runs `$f` as the component, with Component's private methods to hand. */
    private function in(Closure $f): mixed
    {
        return Closure::bind($f, $this->component, Component::class)();
    }

    private function assert(bool $condition, string $message): self
    {
        if (class_exists(\PHPUnit\Framework\Assert::class)) {
            \PHPUnit\Framework\Assert::assertTrue($condition, $message);
        } elseif (!$condition) {
            throw new \AssertionError($message);
        }
        return $this;
    }
}
