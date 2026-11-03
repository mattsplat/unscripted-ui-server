# How it works

## The pieces

- **[`@mtjncn/runtime`](https://www.npmjs.com/package/@mtjncn/runtime)** runs language packs in
  the browser, against the real DOM. `auto.js` finds the page's `<script type="text/php">` tags
  and runs them.
- **[`@mtjncn/php84`](https://www.npmjs.com/package/@mtjncn/php84)** is PHP 8.4 itself, php-src
  compiled to WebAssembly: about 5 MB, 1.5 MB compressed, cached after the first visit.
- **`ui.php`** ([`@mtjncn/ui`](https://www.npmjs.com/package/@mtjncn/ui)) is the component
  framework, one PHP file, run by that PHP. ui-server serves a copy of it at `/unscripted/ui.php`.
- **`unscripted/ui-server`** is the Laravel side: it serves components with their server
  methods stubbed out, renders them on the server, and runs the server methods.

## A request's life

1. Laravel renders your page. `@unscripted('todos')` runs the component on the server, with
   the same `ui.php`, and puts its HTML and state in the page.
2. The browser loads the runtime and PHP, then `ui.php`, which fetches each component's
   browser copy from `/unscripted/components/<name>.blade.php`.
3. Each component takes the state the server rendered with, and from then on runs in the
   browser: events call actions, actions change properties, and the template renders again.
4. A `#[Server]` stub returns a description of the call. `yield` hands it to the framework,
   which sends it to `/unscripted/call` (or over the [socket](websockets.md)), and resumes the
   action with the result.

## Rendering

**A render after every action**, as in Livewire, rather than signals or a virtual DOM. Each
render patches the DOM in place with the runtime's `morph`: elements that are still there are
kept, and only what changed is updated, so focus, the caret, scroll positions and anything else
on them survive. Give list items a `u-key`, so they're matched by identity rather than position.

Events are delegated: one listener per event type on the component's root, not one per element.
Each component handles events only up to its children's roots.

## Generators, not async

PHP has no `async`/`await`, and the browser's PHP is built without Fibers (WebAssembly outside
the browser has no stack switching). So an action that waits returns a `Generator`, and `yield`
is its `await`.

For the browser's own promises, `promise()` wraps one so it can chain:

```php
use function Unscripted\Ui\promise;
use Unscripted as U;

promise(U::fetch('/api/weather'))
    ->then(fn($response) => $response->json())
    ->then(function ($data) { $this->weather = $data; $this->refresh(); });
```

## 32-bit integers

The browser's PHP is 32-bit: an `int` stops at 2,147,483,647 there, while the server's goes
much higher. A larger number from JSON arrives as a `float`, and `(int)` of one wraps around.
Keep timestamps in milliseconds as floats (`microtime(true) * 1000`), and watch for ids or
counts that could pass two billion.

## Limits worth knowing

- **The first visit downloads PHP**, 1.5 MB compressed. The page is server-rendered meanwhile,
  but not interactive. [`u-navigate`](navigation.md) avoids it on later pages.
- **Browsers:** Chromium is what's tested so far.
- **Laravel only:** ui-server needs Laravel 12 or 13.
- **A dropped call isn't cancelled.** When an action starts again, the earlier result is
  ignored, but the server still did the work.
- **Partials shift line numbers** in errors below them.
- **Browser validation is a subset** of Laravel's rules, and its `email`, `url` and `integer`
  checks are looser. The server always checks everything again.
- **Not available:** `u-dirty` and `u-transition`. Use CSS for transitions.
