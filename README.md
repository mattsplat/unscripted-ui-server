# unscripted/ui-server

Livewire-style components for Laravel 12 and 13, that run in the browser. A component is a PHP
class and a Blade template; PHP 8.4, compiled to WebAssembly, runs it on the page, so clicks
and re-renders need no request. Methods you mark `#[Server]` stay on your server, and the
browser calls them.

```blade
<?php
use App\Models\Todo;
use Unscripted\Ui\{Component, Server, State, Validate, Watch};

new class extends Component {
    #[State] public string $draft = '';
    public array $todos = [];

    #[Server, Watch]
    public static function todos(): array
    {
        return Todo::latest()->get(['id', 'title'])->toArray();
    }

    #[Server]
    public static function create(#[Validate('required|max:100', as: 'draft')] string $title): void
    {
        Todo::create(['title' => $title]);
    }

    public function add(): Generator
    {
        yield static::create($this->draft);
        $this->draft = '';
    }
};
?>

<form u-on:submit.prevent="add">
    <input u-model="draft"> <button>Add</button>
</form>
@error('draft') <p>{{ $message }}</p> @enderror
@foreach ($todos as $todo)
    <p u-key="{{ $todo['id'] }}">{{ $todo['title'] }}</p>
@endforeach
```

## Install

It isn't on Packagist yet: install it from its Git repo.

```sh
composer config repositories.unscripted vcs https://github.com/mattsplat/unscripted-ui-server
composer require unscripted/ui-server:dev-main
```

Then put components in `resources/components/`, and load the framework and PHP on your pages.
See [installation](docs/installation.md).

## Documentation

**[Read the docs](docs/README.md).** Start with the [quickstart](docs/quickstart.md), or
[coming from Livewire](docs/coming-from-livewire.md) if you know it.

- Essentials: [components](docs/components.md), [properties](docs/properties.md),
  [actions](docs/actions.md), [server methods](docs/server-methods.md), [forms](docs/forms.md),
  [events](docs/events.md), [lifecycle hooks](docs/lifecycle-hooks.md),
  [nested components](docs/nested-components.md)
- Features: [loading states](docs/loading-states.md), [polling](docs/polling.md),
  [pagination](docs/pagination.md), [file uploads](docs/file-uploads.md),
  [streaming](docs/streaming.md), [navigation](docs/navigation.md),
  [live updates](docs/live-updates.md), [server rendering](docs/server-rendering.md),
  [WebSockets](docs/websockets.md)
- Advanced: [security](docs/security.md), [testing](docs/testing.md),
  [configuration](docs/configuration.md), [how it works](docs/how-it-works.md),
  [reference](docs/reference.md)

## Developing

This package's code lives in [unscripted-js](https://github.com/mattsplat/unscripted-js), at
`packages/ui-server`, beside the browser packages. Change it there, then
`npm run split-ui-server` copies it, history and all, to
[mattsplat/unscripted-ui-server](https://github.com/mattsplat/unscripted-ui-server).

- `src/ui.php` is a copy of `packages/ui/ui.php`, so the server renders exactly what the browser
  does: `npm run sync-ui` after changing the original. The tests fail if they differ.
- `composer test` runs this package's own tests, `tests/components.php`, without Laravel.

## License

MIT OR Apache-2.0 (`LICENSE-MIT`, `LICENSE-APACHE`).
