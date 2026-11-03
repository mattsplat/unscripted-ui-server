# unscripted/ui-server

The server side of [`@unscripted/ui`](../ui), for Laravel 12 and 13: a component's `#[Server]`
methods run on the server, and the browser calls them. PHP 8.4.

It isn't on Packagist: install it from its Git repo (private, so with an SSH key that can read it).

```sh
composer config repositories.unscripted vcs git@github.com:mattsplat/unscripted-ui-server.git
composer require unscripted/ui-server:dev-main
```

Its code lives in [unscripted-js](https://github.com/mattsplat/unscripted-js), at
`packages/ui-server`: change it there, then `npm run split-ui-server` copies it, history and all,
to `mattsplat/unscripted-ui-server`.

Put components in `resources/components/<name>.blade.php`. The package adds three routes, in the
`web` middleware group (sessions, auth, CSRF):

| Route | Does |
|---|---|
| `GET /unscripted/ui.php` | `@unscripted/ui`, the same version the server renders with |
| `GET /unscripted/components/<name>.blade.php` | The component as the browser gets it: each public `#[Server]` method's body replaced by a stub, private and protected ones removed. Line numbers stay the file's |
| `POST /unscripted/call` | `{"calls": [{"component": "todos", "method": "create", "args": ["Milk"]}]}` ⇒ `{"results": [...]}`, in order. Only public static `#[Server]` methods can be called |

A page puts a component in with `@unscripted('todos')`, with the CSRF token for the calls:

```html
<meta name="csrf-token" content="{{ csrf_token() }}">
@unscripted('todos')
<script type="text/php" src="/unscripted/ui.php"></script>
<script src="https://cdn.jsdelivr.net/npm/@unscripted/runtime/auto.js"
        data-php="https://cdn.jsdelivr.net/npm/@unscripted/php84/php_pack.wasm"></script>
```

See [`@unscripted/ui`'s README](../ui#server-methods) for writing them.

## Rendered on the server

`@unscripted('todos')` renders the component on the server, with the real `ui.php`: its
`#[Watch]` loaders run first (through the same path as calls, so `can` is checked), then the
template. The page arrives with the HTML in a `<div id="unscripted-todos">` and the state it was
rendered from on the `<script>` after it. When PHP has loaded in the browser, the component
takes that state, counts its loaders as run (no request), and patches the server's HTML rather
than replacing it.

- **State must be JSON:** public properties go to the browser through `json_encode`, so keep
  them to arrays and scalars (an Eloquent model's `toArray()`, not the model). Readonly
  properties aren't sent.
- **Until PHP loads,** the HTML can't be used (buttons do nothing), though `u-model` fields show
  their values: the server fills them in.
- **A template runs in both places,** so use only what both have: no Laravel helpers in it.
- **`@child` components** render with their parent, each with its own loaders and state.
- **Options:** `@unscripted('todos', ['id' => 'second'])` gives the root another id (to put a
  component on a page twice); `['lazy' => true, 'placeholder' => '<p>Loading…</p>']` skips the
  server render (the browser loads it). `#[Url]` properties come from the request's query.

**A whole page** for a component: `Route::unscripted('/todos', 'todos', ['title' => 'Todos'])`.
It uses ui-server's page (`unscripted::page`), loading the runtime and pack from the CDN unless
`unscripted.runtime` and `unscripted.pack` say otherwise; `['layout' => 'layouts.app']` uses
your view instead, given the component as `$slot` and `$title` (it loads ui.php and the runtime
itself).

**Partials:** `@include('row', ['todo' => $todo])` and `<x-row :todo="$todo">slot</x-row>` in a
component's template are inlined from `<components>/row.blade.php` (a template with no PHP
block, `@props([...])` for defaults), each run in a function of its own with the template's
variables, the ones given (an `<x-...>`'s attributes, camelCased; `:attr` is PHP) and `$slot`.
They're inlined because the browser can't fetch files in the middle of a render. Lines shift
below an inlined partial, so an error's line there is approximate.

The server's `ui.php` is a copy of `@unscripted/ui`'s (`npm run sync-ui` in unscripted-js; the
tests fail if it's out of date). Load the browser's from `/unscripted/ui.php` so the two match.

## Over a WebSocket (Reverb)

Opt in, and the page's calls go over one WebSocket to [Laravel Reverb](https://reverb.laravel.com)
instead of a POST each:

```php
// config/unscripted.php
return ['transport' => 'reverb'];   // default 'http'
```

It needs `laravel/reverb`, set up as Laravel's broadcaster (`php artisan install:broadcasting
--reverb`), and `php artisan reverb:start` running beside the app. Octane isn't needed: Reverb is
its own long-running process. Components must go in with `@unscripted(...)`, which tells the
browser where the socket is (from the `reverb` broadcasting connection, or
`unscripted.socket_url` if the browser reaches it at another address).

- **Who's calling:** a logged-in user's socket joins the private channel
  `private-unscripted.<id>`, which `/broadcasting/auth` signs with their session (ui-server
  registers the channel). Each call runs logged in as the user whose channel the connection is
  in, so `auth()`, gates and `can` work as over HTTP. With no channel it's a guest. Logging out
  doesn't close a socket: the user stays logged in on it until the page is closed.
- **No middleware per message:** nothing in the `web` group runs for a call, so no session,
  no CSRF check (the channel signature stands in), and no throttling except Reverb's own
  rate limit. `session()` in a server method doesn't have the user's session.
- **One process for everyone:** the methods run in the Reverb process, one message at a time.
  A slow method holds up every socket on that server until it returns, so keep them quick or
  run more Reverb servers (with Reverb's scaling). Like Octane, the process lives on, so static
  state in your code is shared between users' calls.
- **Errors** come back as over HTTP: a validation failure is a 422 with its `errors`, a denied
  gate a 403, and so on, arriving in the component as a `ServerError`. Other exceptions are
  reported and sent as a 500 ("Server Error" unless `app.debug`).
- **Size:** Reverb refuses messages over its `max_message_size` (10 KB by default), which
  limits a call's arguments.
- **If it's down,** the calls waiting on it fail with a `ServerError`, and the next call opens
  a new socket. `POST /unscripted/call` stays up either way.

**Restart Reverb after deploying**, as you would Octane: it's a long-running process, so it
keeps the code it started with.

## Live updates

`#[Live('todos')]` on a `#[Watch]` loader runs it again on every page that has it, whenever the
server calls `\Unscripted\UiServer\Live::changed('todos')`, typically at the end of a
`#[Server]` method that changed what the loader reads. With the `reverb` transport, pages hear
about changes on their socket, on the public channel `unscripted-live.todos`; without it, they
ask every `poll` ms (`#[Live('todos', poll: 5000)]`).

The message only says which key changed, never the data: each page's loader fetches that over
its own call, so auth and `can` still apply. Inside Reverb's process, `changed()` publishes
directly; elsewhere (an HTTP call, a queued job) it goes through Laravel's broadcaster. The page
that made the change isn't sent the message over the socket, since its loaders run again after
its calls anyway. If the socket drops, it reconnects after two seconds and reruns its live
loaders, in case it missed something.

### Sending data

For what changes too often to refetch, such as players' positions in a game,
`Live::send('game', $data)` sends the data itself. Every page's `#[Live('game')]` browser method
(not a loader) is called with it, then renders:

```php
#[Server]
public static function move(string $token, float $x, float $y): void
{
    // ...check the token...
    Live::send('game', ['player' => [$id, $x, $y]]);
}

#[Live('game')]
public function heard(array $message): void { /* show the player */ }
```

The channel is public, so send only what anyone may see. It needs the `reverb` transport (there's
nothing to poll), and what's sent while a page's socket is down is lost: give the same key to a
loader, and it runs again when the socket is back. The Laravel demo's `/voxel`
uses it for a shared 3D block world.

## Errors

The server loads a component's PHP block and template from compiled copies beside Laravel's compiled views
(`php artisan view:clear` clears them), made again when the file changes. An exception from it
names the component's own file and line.

A method's exceptions are Laravel's to report, as JSON: a `ValidationException` is a 422 with
its messages, which the browser gets as a `ServerError` with `$errors`. A call to something
that isn't a `#[Server]` method is a 404; arguments that don't fit the method's parameters are
a 422. Arguments are JSON, converted as PHP converts arguments to typed parameters (`"3"` to
`int`), so validate anything more: `#[Validate('rules')]` on a parameter has ui-server run
Laravel's validator on its argument before the method (after `can`), a failure being a 422 with
`errors` by parameter name. Validation doesn't go through Laravel's middleware, so the
argument isn't trimmed first (`required` treats a blank string as missing anyway).

## Streaming

A `#[Server]` method that's a generator streams: called with `->into('text')` from an action,
the browser gets a JSON line per chunk (`application/x-ndjson`) and appends each to `$text` as it
comes. Over Reverb, the method runs through first and the text arrives at the end. Called
without `->into()`, it's run through: the result is its return value, or the chunks joined.

## Uploads

A `u-model` file input posts each file to `POST /unscripted/upload` as it's chosen, checked
against `unscripted.upload_rules` (`required|file|max:10240` by default; a failure is the field's
error). It's kept in `storage/app/unscripted-uploads` for an hour, and only the session or
user that uploaded it can see it (`GET /unscripted/upload/<id>`, for a preview) or take it in a
server method: `Upload::find($photo)` (the id or the array the page holds) gives `->name`,
`->size`, `->type`, `->path()`, `->contents()`, `->store('dir', 'disk')` and `->delete()`.
Over Reverb there's no session, so only logged-in users can find their uploads there.

## Config

`config/unscripted.php`, if you need it:

```php
return [
    'components' => resource_path('components'),
    'middleware' => ['web', 'throttle:60,1'],   // add rate limiting, or auth for every call
    'transport' => 'http',                         // or 'reverb'
    'socket_url' => null,                          // where the browser reaches Reverb, if not the broadcaster's
    'upload_rules' => 'required|file|max:10240',
    'runtime' => null,                             // Route::unscripted()'s script URLs (CDN by default)
    'pack' => null,
];
```

## Security

Every public `#[Server]` method is an HTTP endpoint anyone can call with any arguments: check
authorisation and validate in the method. `#[Server(can: 'ability')]` checks a Gate ability
before the method runs, with the call's arguments: a gate's first parameter is the user
(`?User` to let guests through), the rest are the method's arguments. The rest of the file (client methods, `use` lines,
the template) goes to the browser as written.

## Testing

Drive a component without a browser, in PHPUnit:

```php
$todos = app(Components::class)->test('todos');
$todos->set('draft', 'Milk')->call('add')
    ->assertSee('Milk')->assertSet('draft', '')->assertHasNoErrors();
$todos->call('add')->assertHasErrors('title');
$todos->call('remove', $id)->assertFailed(403);
```

It's built from the source the browser gets, so its server calls go through `can` and
`#[Validate]` as a page's do (without middleware). It has `set`, `call`, `get`, `html`,
`assertSee`, `assertDontSee`, `assertSet`, `assertHasErrors`, `assertHasNoErrors`,
`assertDispatched`, `assertRedirect` and `assertFailed`. A failed call the component doesn't
handle is kept for `assertFailed()`; an exception that's a bug is thrown to the test.

`composer test` runs this package's own tests, `tests/components.php`, without Laravel.

## License

MIT OR Apache-2.0 (`LICENSE-MIT`, `LICENSE-APACHE`).
