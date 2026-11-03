# Components

## The file

A component is one file in `resources/components/`, named after the component:
`resources/components/todos.blade.php` is `todos`. The class comes first, in a PHP block that
returns an anonymous class extending `Component`; the Blade template follows.

```blade
<?php
use Unscripted\Ui\{Component, State};

new class extends Component {
    #[State] public string $name = '';
};
?>

<input u-model="name">
<p>Hello, {{ $name ?: 'stranger' }}!</p>
```

The `use` lines, the class and the template are all sent to the browser, except the bodies of
`#[Server]` methods. See [security](security.md) for what that means.

## Putting it on a page

In any Blade view, with the [page's scripts](installation.md#the-page) loaded after it:

```blade
@unscripted('todos')
```

That renders the component on the server, so the page arrives with its HTML, then the browser
takes over once PHP has loaded. The options:

```blade
@unscripted('todos', ['id' => 'second-list'])            {{-- the same component twice on a page --}}
@unscripted('stats', ['lazy' => true, 'placeholder' => '<p>Loading…</p>'])   {{-- no server render --}}
```

A component's root element is `<div id="unscripted-todos">` unless you pass an `id`.

### As a whole page

```php
Route::unscripted('/todos', 'todos', ['title' => 'Todos']);
Route::unscripted('/todos', 'todos', ['title' => 'Todos', 'layout' => 'layouts.app']);
```

Without `layout`, the package's own minimal page is used, loading the runtime and PHP from the
CDN (or from `unscripted.runtime` and `unscripted.pack` in the [config](configuration.md)). With
it, your view gets the component as `$slot` and `$title`, and loads the scripts itself.

## Templates

The template sees the component's public properties as variables, and `$this` too, private
methods included: `{{ $this->label() }}`.

| | |
|---|---|
| Echo | `{{ $x }}` (escaped), `{!! $html !!}` (not escaped), `{{-- comment --}}`, `@{{ shown as written }}` |
| Conditions | `@if`, `@elseif`, `@else`, `@endif`, `@unless`, `@isset`, `@empty(...)` |
| Loops | `@foreach`, `@forelse` / `@empty` / `@endforelse`, `@for`, `@while`, `@break`, `@continue`, with or without a condition |
| Attributes | `@class(['done' => $todo['done'], 'item'])`, `@checked(...)`, `@selected(...)`, `@disabled(...)`, `@readonly(...)`, `@required(...)` |
| Errors | `@error('field') {{ $message }} @enderror` |
| Components | `@child('name', $props, $key)` for [nested components](nested-components.md) |
| Partials | `@include('row', [...])`, `<x-row :todo="$todo">slot</x-row>`, `@props([...])` |
| Other | `@php ... @endphp`, `@php(...)`, `@json(...)` (safe inside `<script>`), `@verbatim`, `@@` for a literal `@` |

Other `@words` are left alone, so email addresses and CSS `@media` rules are safe.

**A template runs in the browser** (and on the server for the first load), so it can only use
what both have. Laravel's helpers (`route()`, `auth()`, `__()`, `config()`) aren't in the
browser: get their values from a [loader](server-methods.md#loaders) instead.

Layouts (`@extends`, `@section`) aren't for components. A component goes in a page, and pages
are ordinary Laravel views.

## Partials

A partial is a template with no PHP block, for markup you repeat. Put it beside your
components, in `resources/components/row.blade.php`:

```blade
@props(['tone' => 'grey'])
<li class="row {{ $tone }}">{{ $todo['title'] }} {{ $slot }}</li>
```

And use it from a component's template:

```blade
@include('row', ['todo' => $todo])
<x-row :todo="$todo" tone="green"><button>×</button></x-row>
```

- `@props` sets defaults. An `<x-...>` tag's attributes become variables, camelCased
  (`due-date` is `$dueDate`); a `:attr` is PHP, a plain one is a string. What's between the tags
  is `$slot`.
- A partial sees the variables it's given and the template's own, and nothing else.
- Partials are inlined into the component when it's served, because the browser can't fetch
  files in the middle of a render. Line numbers in errors below a partial are approximate.

## Errors

An exception in a component names its own file and line, in the browser's console and in
Laravel's error page on the server:

```
Division by zero in todos.blade.php:58
```

The server compiles a component's PHP and template into files beside Laravel's compiled views,
and again when the file changes. `php artisan view:clear` clears them.

## Without Blade

A named class with a `render()` method that prints HTML, in PHP's own template syntax, works
too. It's mounted from a page's own `<script type="text/php">`, without ui-server's routes:

```php
<?php
use Unscripted\Ui\{Component, State};
use function Unscripted\Ui\e;

final class Greeter extends Component
{
    #[State] public string $name = '';

    protected function render(): void { ?>
        <input u-model="name">
        <p>Hello, <?= e($this->name ?: 'stranger') ?>!</p>
    <?php }
}

new Greeter()->mount('#app');
```

`<?= ?>` doesn't escape: wrap anything a user typed in `e()`.
