# Server methods

Most of a component runs in the browser. A method marked `#[Server]` runs on your Laravel app
instead: the browser gets a stub in its place, and calling the stub makes a request.

```php
use App\Models\Todo;
use Unscripted\Ui\{Component, Server, State, Validate};

new class extends Component {
    #[State] public string $draft = '';

    #[Server]
    public static function create(#[Validate('required|max:100')] string $title): array
    {
        return Todo::create(['title' => $title])->only('id', 'title', 'done');
    }

    public function add(): Generator
    {
        $todo = yield static::create($this->draft);
        $this->draft = '';
    }
};
```

## The rules

- **`public static`.** A server method can't see the component, whose state is in the user's
  browser. It gets only its arguments, which the browser sent, so treat them as user input.
- **Arguments and results are JSON.** Arguments are converted to the parameters' types the way
  PHP converts typed arguments (`"3"` to `int`). Return arrays and scalars, never models:
  `->get(['id', 'title'])->toArray()`, `->only(...)`.
- **Every one is an endpoint.** Anyone can call any public `#[Server]` method with any
  arguments, whatever your template does. Check permissions and validate. See
  [security](security.md).
- **`private` and `protected` `#[Server]` methods** are helpers for the public ones. They're
  left out of the browser's copy entirely, not even as stubs.

## Calling them

From an action, with `yield` to wait for the result:

```php
public function save(): Generator
{
    $id = yield static::store($this->form);
    [$a, $b] = yield [static::one(), static::two()];   // both in one request
}
```

Straight from the page, when there's nothing to do with the result:

```blade
<button u-on:click="remove({{ $todo['id'] }})">×</button>
```

After an action's server calls are done (or a direct call's), every [loader](#loaders) runs
again, so the page shows what the server has.

## Loaders

A server method with `#[Watch]` is a loader: it runs when the component mounts, and its result
goes in the property with the method's name.

```php
#[State] public string $query = '';
public array $results = [];

#[Server, Watch('query', debounce: 300)]
public static function results(string $query): array
{
    return Product::where('name', 'like', "%$query%")->limit(20)->get(['id', 'name'])->toArray();
}
```

- `#[Watch]` with no properties loads once on mount (and again after server calls).
- `#[Watch('query')]` also runs whenever `$query` changes, called with its value.
- `#[Watch(['query', 'page'])]` watches several, passed in that order.
- `debounce: 300` waits until the property has stopped changing for 300 ms: right for a
  search box.
- If a loader starts again while it's still waiting, the earlier result is dropped, so fast
  typing shows the last query's results, not the slowest one's.
- On the first, server-rendered load, loaders run on the server before the template, so the
  page arrives with their results.

## Permissions

`can:` names a Laravel Gate ability, checked before the method runs. The gate gets the user
and the call's arguments:

```php
#[Server(can: 'delete-todo')]
public static function remove(int $id): void
{
    Todo::findOrFail($id)->delete();
}
```

```php
// AppServiceProvider::boot()
Gate::define('delete-todo', fn(?User $user, int $id) => Todo::find($id)?->user_id === $user?->id);
```

A denied call is a 403. Type the user `?User` to let guests reach the gate; a plain `User`
denies them. `auth()`, `Gate` and policies work inside the method as in a controller.

## Validation

`#[Validate]` on a parameter runs Laravel's validator on its argument before the method (after
`can`). A failure is a 422 with the messages, which fill the component's error bag:

```php
#[Server]
public static function create(#[Validate('required|max:100', as: 'draft')] string $title): void
```

See [forms](forms.md) for `as`, whole-form arrays, and checking in the browser as people type.

## Errors

A failed call is thrown into the action at its `yield`, as a `ServerError`:

```php
use Unscripted\Ui\ServerError;

public function save(): Generator
{
    try {
        yield static::store($this->form);
    } catch (ServerError $e) {
        $this->notice = $e->status === 403 ? "You can't do that." : $e->getMessage();
    }
}
```

`$e->status` is the HTTP status, `$e->getMessage()` the message, and `$e->errors` a validation
failure's messages by field.

- **Validation failures needn't be caught.** If the action doesn't, it stops at the `yield`
  and the [error bag](forms.md#showing-errors) shows the messages.
- **Anything else uncaught** goes to the component's `failed(ServerError $e)` hook, which by
  default throws it to the browser's console. Override it to show errors your own way.
- **A loader's validation errors** aren't put in the error bag.
- **On the server**, exceptions are Laravel's to report, as for any JSON request. A call to
  something that isn't a `#[Server]` method is a 404, and arguments that don't fit the
  parameters are a 422. Other exceptions are a 500, with the message only when `app.debug` is on.

## Waiting states

`$this->loading('save')` is true while `save` (an action or loader) waits on the server, and
`u-loading="save"` shows an element only then. See [loading states](loading-states.md).

## How the stub works

The browser fetches the component from `/unscripted/components/<name>.blade.php`, where each
public `#[Server]` method's body has been replaced by a stub that returns a call description.
`yield` hands that to the framework, which posts it to `/unscripted/call` (or sends it over a
[WebSocket](websockets.md)) and resumes the action with the result. Line numbers are kept, so
errors still point at your file.
