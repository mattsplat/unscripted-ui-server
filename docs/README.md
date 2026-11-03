# Unscripted components for Laravel

Reactive components written in PHP and Blade, in the style of Livewire, with one big
difference: **the component runs in the browser.** PHP 8.4 is compiled to WebAssembly, so a
click, a keystroke or a re-render needs no request. Only the methods you mark `#[Server]` run
on your Laravel app, and the browser calls them like functions.

```blade
<?php
use Unscripted\Ui\{Component, State};

new class extends Component {
    #[State] public int $count = 0;

    public function increment(): void
    {
        $this->count++;
    }
};
?>

<button u-on:click="increment">+</button>
<span>{{ $count }}</span>
```

Two packages make it up: [`@mtjncn/ui`](https://www.npmjs.com/package/@mtjncn/ui), the
component framework that runs in the browser, and
[`unscripted/ui-server`](https://github.com/mattsplat/unscripted-ui-server), the Laravel side
that serves components, renders them on the server first, and runs their `#[Server]` methods.

## Getting started

- [Quickstart](quickstart.md): a counter, then a todo list with a database, in ten minutes.
- [Installation](installation.md): the package, the page's scripts, and what you need.
- [Coming from Livewire](coming-from-livewire.md): what's the same, what's different, and why.

## Essentials

- [Components](components.md): files, templates, putting them on a page, partials.
- [Properties](properties.md): state, computed properties, the query string.
- [Actions](actions.md): methods the page calls, events and their modifiers.
- [Server methods](server-methods.md): running code on the server, loaders, errors.
- [Forms](forms.md): binding inputs, and validation in the browser and on the server.
- [Events](events.md): components talking to each other, and to JavaScript.
- [Lifecycle hooks](lifecycle-hooks.md): code that runs as a component mounts, renders and updates.
- [Nested components](nested-components.md): children with their own state.

## Features

- [Loading states](loading-states.md): showing that something's waiting on the server.
- [Polling](polling.md): refreshing on a timer, and loading once a component appears.
- [Pagination](pagination.md): paging through query results.
- [File uploads](file-uploads.md): uploads with progress, kept safe until you store them.
- [Streaming](streaming.md): text from the server as it's made, such as an AI reply.
- [Navigation](navigation.md): moving between pages without loading PHP again.
- [Live updates](live-updates.md): every open page updating when data changes.
- [Server rendering](server-rendering.md): HTML on first load, and lazy components.
- [WebSockets](websockets.md): calls over Laravel Reverb instead of HTTP.

## Advanced

- [Security](security.md): what the browser can see and call, and how to lock it down.
- [Testing](testing.md): driving components in PHPUnit.
- [Configuration](configuration.md): `config/unscripted.php`.
- [How it works](how-it-works.md): stubs, rendering, morphing, and the limits that follow.

## Reference

- [HTML attributes and Blade directives](reference.md#html-attributes)
- [PHP attributes](reference.md#php-attributes)
- [Component methods](reference.md#component-methods)
