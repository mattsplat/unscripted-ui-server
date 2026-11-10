# Configuration

Everything has a default. To change something, create `config/unscripted.php`:

```php
<?php

return [
    // Where components live.
    'components' => resource_path('components'),

    // Middleware for the package's routes (calls and uploads). Add rate limiting, or 'auth'
    // to require login for every call.
    'middleware' => ['web'],

    // How pages call server methods: 'http' (a POST each) or 'reverb' (one WebSocket).
    'transport' => env('UNSCRIPTED_TRANSPORT', 'http'),

    // Where the browser reaches Reverb, if not at the broadcasting connection's address.
    'socket_url' => null,

    // What every component gets as $shared. Each value is a closure, run per page render.
    'share' => [
        'auth' => fn() => ['id' => auth()->id()],
    ],

    // Stop a render that uses Laravel outside a #[Server] method. app.debug by default.
    'check' => env('APP_DEBUG', false),

    // Checked for every upload, before your server method sees it.
    'upload_rules' => 'required|file|max:10240',

    // For Route::unscripted()'s built-in page: the runtime and PHP pack's URLs. The CDN when null.
    'runtime' => null,
    'pack' => null,
];
```

| Key | Default | |
|---|---|---|
| `components` | `resource_path('components')` | The folder components are read from |
| `middleware` | `['web']` | Middleware for `/unscripted/...` routes |
| `transport` | `'http'` | `'reverb'` for [WebSockets](websockets.md) |
| `socket_url` | `null` | The address the browser uses for Reverb, when it differs from the server's (behind a proxy, say) |
| `share` | the signed-in id | What every component gets as `$shared` (see below) |
| `check` | `app.debug` | Stop a render using Laravel outside a `#[Server]` method |
| `upload_rules` | `'required\|file\|max:10240'` | Laravel rules for [uploads](file-uploads.md) |
| `runtime` | jsDelivr | `auto.js`'s URL, for `Route::unscripted()`'s page |
| `pack` | jsDelivr | `php_pack.wasm`'s URL, for the same |

Your own pages load the scripts themselves, so `runtime` and `pack` matter only for
`Route::unscripted()` without a `layout`.

## Sharing data with every component

A component runs in the browser, where Laravel isn't, so `auth()`, `config()` and the rest
can't be called from a template, an action or any other browser code. What those would have
told you, the page shares instead: `share` is run on the server for each page render, sent with
it, and given to every component as `$shared`.

```php
'share' => [
    'auth' => fn() => ['id' => auth()->id(), 'name' => auth()->user()?->name],
    'app' => fn() => ['name' => config('app.name')],
],
```

```blade
@if ($shared['auth']['id'] !== null)
    <p>Hi {{ $shared['auth']['name'] }}</p>
@endif
```

The same values render on the server and in the browser, so the first HTML matches what the
browser draws over it. The default shares only `auth.id`, enough to tell a signed-in page from
a guest's; override `share` to send what your pages need.

Two rules, the same as for any other state:

- **Share only what the user may see.** It's in the page's HTML, where anyone can read it.
- **Never trust it coming back.** A `#[Server]` method doesn't get `$shared`, by design: give
  it what it needs as arguments and check them, as you would a form. See
  [security](security.md).

Anything that changes while the page is open belongs in a [loader](server-methods.md), not
here: `share` is read once, when the page renders. A [`u-navigate`](navigation.md) visit
renders a new page, so it picks up fresh values.

## Catching Laravel in browser code

Everything outside a `#[Server]` method's body runs in the browser: the template, actions,
`mounted()`, `#[Computed]`, the lifecycle hooks. Laravel isn't there, so `auth()` in a template
renders fine on the server and then kills the component the moment it mounts, which is easy to
miss. `mounted()` and actions the server never runs at all.

While `check` is on, a render stops instead, and says where:

```
todos.blade.php: auth() on line 12. Outside a #[Server] method the component runs in the
browser, which has no Laravel: move it into a #[Server] method, or share it with the page
(unscripted.share).
```

It names Laravel's helpers and any class imported from `App\` or `Illuminate\`, so a model or a
facade is caught too. It's `app.debug` by default, since in production the browser's error is
all that's left either way.
