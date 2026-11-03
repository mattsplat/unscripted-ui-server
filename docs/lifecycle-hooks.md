# Lifecycle hooks

Override these on your component to run code as it mounts, renders and changes. They must be
`protected` or `public`: the framework calls them, and can't see private methods.

```php
new class extends Component {
    #[State] public string $query = '';

    protected function mounted(): void {}          // on the page, after its first render
    protected function unmounted(): void {}        // taken off the page
    protected function rendering(): void {}        // before each render
    protected function rendered(): void {}         // after each render, once the DOM shows it

    protected function updatingQuery(string $value): void {}       // before u-model writes $query
    protected function updatedQuery(string $value): void {}        // after
    protected function updated(string $path, mixed $value): void {} // after any u-model write

    protected function failed(ServerError $e): void {}   // a server call failed and nothing caught it
};
```

| Hook | When |
|---|---|
| `mounted()` | Once, when the component is on the page, after its first render. Start timers here, or reach for the DOM |
| `unmounted()` | When it's taken off: a child its parent stopped rendering, or a page left with `u-navigate`. Stop timers here |
| `rendering()` | Before each render |
| `rendered()` | After each render, once the DOM has been updated |
| `updating<Name>($value)` | Before `u-model` writes property `<name>`, with the new value |
| `updated<Name>($value)` | After it does |
| `updated($path, $value)` | After any `u-model` write, with its path (`form.title`) |
| `failed(ServerError $e)` | When a server call fails and the action didn't catch it. The default throws the error, to the console |

There's no `mount()` with parameters, as in Livewire: a component's starting values are its
property defaults, its [props](nested-components.md) from a parent, its [`#[Url]`](properties.md#in-the-query-string)
properties, and its [loaders](server-methods.md#loaders).

## A timer

```php
use Unscripted as U;

private ?int $timer = null;

protected function mounted(): void
{
    $this->timer = U::setInterval(fn() => $this->tick(), 1000);
}

protected function unmounted(): void
{
    U::clearInterval($this->timer);
}

private function tick(): void
{
    $this->seconds++;
    $this->refresh();   // changed outside an action, so render by hand
}
```
