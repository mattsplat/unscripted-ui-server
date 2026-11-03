# Properties

A component's public properties are its state. The template sees each one as a variable, and
the component renders again whenever an action changes one.

```php
new class extends Component {
    #[State] public string $draft = '';     // u-model may write it
    public array $todos = [];               // shown, filled by a loader, not bound to inputs
    public int $total = 0;
};
```

## `#[State]`: what inputs may write

`u-model` writes only public properties marked `#[State]`. A property without it can still
change in an action, from a loader or from a parent's props, but not from an input.

`readonly` and `private(set)` properties can't be bound either, even with `#[State]`:

```php
#[State] public private(set) array $rows = [];   // shown, never written by u-model
```

**This isn't a security boundary.** The component runs in the user's browser, so a determined
user can change any property. Treat what the browser sends a [server method](server-methods.md)
as user input, always.

## Types

Properties can be any PHP type the browser has. Two things to keep in mind:

- **Server-rendered state must be JSON.** On the first load, public properties go from the
  server to the browser through `json_encode`. Keep them to arrays and scalars: an Eloquent
  model's `toArray()`, not the model. Readonly properties aren't sent.
- **`int` is 32-bit in the browser**, up to 2,147,483,647. See
  [how it works](how-it-works.md#32-bit-integers).

## Changing state

Change a property in an action and the component renders again afterwards:

```php
public function clear(): void
{
    $this->draft = '';
}
```

State changed outside an action, in a timer or a callback, needs a nudge:

```php
protected function mounted(): void
{
    U::setInterval(function () {
        $this->now = microtime(true) * 1000;
        $this->refresh();
    }, 1000);
}
```

(`use Unscripted as U;` gives you the browser: `U::window()`, `U::document()`, `U::fetch()`,
`U::setTimeout()` and so on.)

## Computed properties

A method marked `#[Computed]` reads like a property, `$this->remaining`, and runs at most once
per render:

```php
use Unscripted\Ui\Computed;

#[Computed]
public function remaining(): int
{
    return count(array_filter($this->todos, fn($t) => !$t['done']));
}
```

```blade
<p>{{ $this->remaining }} left</p>
```

## In the query string

`#[Url]` keeps a property in the address, so a reload or a shared link starts from it:

```php
use Unscripted\Ui\Url;

#[State, Url(as: 'q')] public string $query = '';   // ?q=milk
#[State, Url] public int $page = 1;                  // ?page=2
```

It's read when the component mounts, on the server for the first render too, and written back
with `history.replaceState` as it changes. It's left out of the address while it has its
default value.
