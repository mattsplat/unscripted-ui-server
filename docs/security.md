# Security

The component runs in the user's browser, so think of it as you would JavaScript: the user can
read all of it and change any of it. Your server methods are the boundary.

## Every server method is a public endpoint

Anyone can call any public `#[Server]` method, with any arguments, whatever your template does
with it. Treat each one as a controller action that takes user input:

- **Check permission** with `#[Server(can: 'ability')]`, a policy, or in the method.
- **Validate arguments** with [`#[Validate]`](forms.md#validation), or in the method.
- **Don't trust state.** Server methods don't see the component's properties, by design. If a
  method needs a value, take it as an argument and check it.

```php
#[Server(can: 'update-note')]
public static function rename(int $id, #[Validate('required|max:100')] string $title): void
{
    Note::findOrFail($id)->update(['title' => $title]);
}
```

```php
Gate::define('update-note', fn(User $user, int $id) => Note::find($id)?->user_id === $user->id);
```

## Return only what the page may see

What a server method returns goes to the browser. Pick the columns:

```php
return User::where('team_id', $teamId)->get(['id', 'name'])->toArray();   // not ->get()
```

## What the browser receives

The browser's copy of a component (`/unscripted/components/<name>.blade.php`) is the file as
you wrote it, except:

- **Public `#[Server]` methods' bodies** are replaced by stubs. Their default parameter values
  are copied into the stubs, though, so don't put secrets there.
- **Private and protected `#[Server]` methods** are removed entirely.

Everything else is sent as written: the browser methods, the template, `use` lines (which name
your classes), and `#[Validate]` rules. Keep secrets, keys and queries in server methods.

## Middleware

Calls to `POST /unscripted/call` go through the `web` group: sessions, auth and CSRF. Add rate
limiting, or require login for every call, with the `middleware` option in the
[config](configuration.md):

```php
'middleware' => ['web', 'auth', 'throttle:60,1'],
```

Over the [WebSocket transport](websockets.md), no middleware runs per message: authenticate
through the socket's private channel, and rely on Reverb's rate limiting.

## Escaping

`{{ }}` escapes; `{!! !!}`, and `<?= ?>` in a `render()` method, don't. Use `{!! !!}` only for
HTML you made yourself, and `e()` in `<?= ?>`.

## Live channels are public

[`Live::changed()`](live-updates.md) sends only a key, and each page then fetches through its
own checked call. `Live::send()` sends data on a public channel that anyone can listen to: send
only what anyone may see.
