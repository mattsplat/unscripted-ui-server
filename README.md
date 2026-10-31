# unscripted/ui-server

The server side of [`@unscripted/ui`](../ui), for Laravel 12 and 13: a component's `#[Server]`
methods run on the server, and the browser calls them. PHP 8.4.

```sh
composer require unscripted/ui-server
```

Put components in `resources/components/<name>.blade.php`. The package adds two routes, in the
`web` middleware group (sessions, auth, CSRF):

| Route | Does |
|---|---|
| `GET /unscripted/components/<name>.blade.php` | The component as the browser gets it: each public `#[Server]` method's body replaced by a stub, private and protected ones removed. Line numbers stay the file's |
| `POST /unscripted/call` | `{"calls": [{"component": "todos", "method": "create", "args": ["Milk"]}]}` ⇒ `{"results": [...]}`, in order. Only public static `#[Server]` methods can be called |

A page mounts a component from its route, with the CSRF token for the calls:

```html
<meta name="csrf-token" content="{{ csrf_token() }}">
<div id="app"></div>
<script type="text/blade" src="/unscripted/components/todos.blade.php" data-mount="#app"></script>
<script type="text/php" src="https://cdn.jsdelivr.net/npm/@unscripted/ui/ui.php"></script>
<script src="https://cdn.jsdelivr.net/npm/@unscripted/runtime/auto.js"
        data-php="https://cdn.jsdelivr.net/npm/@unscripted/php84/php_pack.wasm"></script>
```

See [`@unscripted/ui`'s README](../ui#server-methods) for writing them.

## Errors

The server loads a component's PHP block from a compiled copy beside Laravel's compiled views
(`php artisan view:clear` clears them), made again when the file changes. An exception from it
names the component's own file and line.

A method's exceptions are Laravel's to report, as JSON: a `ValidationException` is a 422 with
its messages, which the browser gets as a `ServerError` with `$errors`. A call to something
that isn't a `#[Server]` method is a 404; arguments that don't fit the method's parameters are
a 422. Arguments are JSON, converted as PHP converts arguments to typed parameters (`"3"` to
`int`), so validate anything more.

## Config

`config/unscripted.php`, if you need it:

```php
return [
    'components' => resource_path('components'),
    'middleware' => ['web', 'throttle:60,1'],   // add rate limiting, or auth for every call
];
```

## Security

Every public `#[Server]` method is an HTTP endpoint anyone can call with any arguments: check
authorisation and validate in the method. The rest of the file (client methods, `use` lines,
the template) goes to the browser as written.

## Testing

`composer test` runs `tests/components.php`: stripping, and calling, without Laravel.

## License

MIT OR Apache-2.0 (`LICENSE-MIT`, `LICENSE-APACHE`).
