# Navigation

Loading PHP into the browser takes a moment on a page's first visit. `u-navigate` keeps it
loaded as people move between your pages.

```blade
<nav>
    <a u-navigate href="/todos">Todos</a>
    <a u-navigate href="/settings">Settings</a>
</nav>
```

A `u-navigate` link fetches the next page and swaps its `<body>` in, without reloading PHP or
the runtime. The `<head>`'s styles and the title follow. The old page's components are
unmounted, the new page's are mounted, and back and forward work as usual.

## Keeping an element

```blade
<div u-persist="player" id="player">
    <audio src="/radio.mp3" controls></audio>
</div>
```

An element with `u-persist` stays exactly as it is across `u-navigate` page changes, where the
next page has an element with the same id: the audio keeps playing. A component inside it keeps
its state.
