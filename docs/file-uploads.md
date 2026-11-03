# File uploads

A file input with `u-model` uploads its file as soon as it's chosen. A server method then
takes it from there.

```blade
<?php
use Unscripted\Ui\{Component, Server, State};
use Unscripted\UiServer\Upload;

new class extends Component {
    #[State] public ?array $photo = null;
    public ?string $saved = null;

    #[Server]
    public static function save(array $photo): string
    {
        $upload = Upload::find($photo) ?? abort(422, 'Upload it again');
        return $upload->store('avatars');
    }

    public function keep(): Generator
    {
        $this->saved = yield static::save($this->photo);
    }
};
?>

<input type="file" u-model="photo" accept="image/*">

@if ($this->loading('photo'))
    <p>Uploading… {{ $this->progress('photo') }}%</p>
@elseif ($photo)
    <img src="{{ $photo['url'] }}" width="120">
    <button u-on:click="keep">Use this photo</button>
@endif
@error('photo') <p class="error">{{ $message }}</p> @enderror
```

## While it uploads

The file goes to `POST /unscripted/upload` the moment it's chosen. Meanwhile
`$this->loading('photo')` is true and `$this->progress('photo')` is the percent done.

## Afterwards

The property holds a description of the file, not the file:

```php
['id' => '…', 'name' => 'me.jpg', 'size' => 48211, 'type' => 'image/jpeg', 'url' => '…']
```

With `multiple` on the input, it's a list of them. `url` shows the file, to this visitor
only, for a preview.

## Taking it on the server

`Upload::find()` takes the id or the whole array, and gives the file, or `null` if it's gone or
belongs to someone else:

| | |
|---|---|
| `->name`, `->size`, `->type` | The original name, the size in bytes, the MIME type |
| `->path()` | Where it is on disk now |
| `->contents()` | Its bytes |
| `->store('dir', 'disk')` | Moves it into your storage, returning the path (as Laravel's `store()`) |
| `->delete()` | Throws it away |

## Rules and safety

- Every upload is checked against `upload_rules` in the [config](configuration.md),
  `required|file|max:10240` by default (10 MB). A failure is the field's error. Check the file
  again in your server method for what this one needs (`image`, `mimes:...`).
- Uploads wait in `storage/app/unscripted-uploads` for an hour, then they're gone. Store the
  ones you keep.
- Only the session or user that uploaded a file can see it or take it. Over the
  [WebSocket transport](websockets.md) there's no session, so only logged-in users can find
  their uploads there.
