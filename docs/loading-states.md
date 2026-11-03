# Loading states

Actions in the browser are instant. Server calls aren't, so show when one is waiting.

## `u-loading`

```blade
<button u-on:click="save">Save</button>
<span u-loading="save">Saving…</span>        {{-- only while save() waits on the server --}}
<span u-loading>Working…</span>              {{-- while anything does --}}
<span u-loading.remove="save">All saved</span>   {{-- hidden while save() waits --}}
```

The name is an action's or a [loader's](server-methods.md#loaders): `u-loading="results"`
shows while the `results()` loader is fetching, as for a search box.

## In the template or the component

`$this->loading('save')` is true while `save` waits; `$this->loading()` while anything does.

```blade
<button u-on:click="save" @disabled($this->loading('save'))>
    {{ $this->loading('save') ? 'Saving…' : 'Save' }}
</button>
```

## Upload progress

While a file uploads, `$this->progress('photo')` is the percent done. See
[file uploads](file-uploads.md).

## Offline

```blade
<p u-offline>You're offline. Changes will fail until you're back.</p>
```

`u-offline` elements show only while the browser is offline.
