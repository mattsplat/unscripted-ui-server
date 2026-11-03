# Server rendering

`@unscripted('todos')` renders the component on the server first, so the page arrives with its
HTML, before PHP has loaded in the browser.

## What happens

1. **On the server:** the component's `#[Url]` properties are read from the request, its
   [loaders](server-methods.md#loaders) run (through the same path as calls, so `can` is
   checked), and its template renders, with the server's copy of the framework, the same file
   the browser runs. [Child components](nested-components.md) render with it.
2. **The page** has the HTML in `<div id="unscripted-todos">`, and the state it was rendered
   from in a `<script>` after it.
3. **In the browser,** once PHP has loaded, the component takes that state, counts its loaders
   as already run (no request), and patches the server's HTML in place rather than replacing it.

## Until PHP loads

The HTML is there but nothing works yet: buttons do nothing. `u-model` fields do show their
values, filled in by the server. On later pages, [`u-navigate`](navigation.md) avoids the wait,
since PHP stays loaded.

## What it needs from you

- **State must be JSON.** Public properties reach the browser through `json_encode`: keep them
  to arrays and scalars. Readonly properties aren't sent.
- **The template must run in both places.** No Laravel helpers in it; see
  [components](components.md#templates).

## Lazy components

To skip the server render, for a component that's slow to load or below the fold:

```blade
@unscripted('stats', ['lazy' => true, 'placeholder' => '<p>Loading stats…</p>'])
```

The page shows the placeholder, and the browser loads the component itself once PHP is ready.

## Twice on one page

```blade
@unscripted('counter')
@unscripted('counter', ['id' => 'second-counter'])
```
