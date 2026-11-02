<?php

namespace Unscripted\UiServer;

use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * A file uploaded by a page's `u-model` file input (POST /unscripted/upload), kept for an hour
 * until a #[Server] method takes it: `Upload::find($photo)?->store('avatars')`. Only the
 * session or user that uploaded it can find it.
 */
final class Upload
{
    /** An hour, in seconds: older uploads are cleared. */
    private const int KEEP = 3600;

    private function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly int $size,
        public readonly string $type,
    ) {}

    /** The upload `$upload` names (its id, or the array the page holds), if it's this request's
     * user's or session's and still kept; else null. Over Reverb (no session), a user's only. */
    public static function find(string|array|null $upload): ?self
    {
        $id = is_array($upload) ? ($upload['id'] ?? null) : $upload;
        if (!is_string($id) || !preg_match('/^[a-f0-9]{40}$/', $id) || !is_file(self::folder() . "/$id.json")) {
            return null;
        }
        $meta = json_decode(file_get_contents(self::folder() . "/$id.json"), true);
        $user = auth()->id();
        $mine = ($meta['user'] !== null && $user !== null && (string) $meta['user'] === (string) $user)
            || (app()->bound('session') && session()->isStarted() && hash_equals($meta['session'], session()->getId()));
        if (!$mine || $meta['at'] < time() - self::KEEP) {
            return null;
        }
        return new self($id, $meta['name'], $meta['size'], $meta['type']);
    }

    /** Keeps `$file` for the current user/session: what the page gets back. */
    public static function keep(UploadedFile $file): array
    {
        self::sweep();
        $id = bin2hex(random_bytes(20));
        $file->move(self::folder(), "$id.bin");
        $meta = [
            'name' => $file->getClientOriginalName(),
            'size' => filesize(self::folder() . "/$id.bin"),
            'type' => $file->getClientMimeType(),
            'user' => auth()->id(),
            'session' => session()->getId(),
            'at' => time(),
        ];
        file_put_contents(self::folder() . "/$id.json", json_encode($meta));
        return ['id' => $id, 'name' => $meta['name'], 'size' => $meta['size'], 'type' => $meta['type'], 'url' => url("unscripted/upload/$id")];
    }

    public function path(): string
    {
        return self::folder() . "/$this->id.bin";
    }

    public function contents(): string
    {
        return file_get_contents($this->path());
    }

    /** Moves it to `$directory` on a filesystem disk (the default one, unless `$disk`), named by
     * its id and extension: the stored path. */
    public function store(string $directory, ?string $disk = null): string
    {
        $extension = pathinfo($this->name, PATHINFO_EXTENSION);
        $stored = Storage::disk($disk)->putFileAs($directory, new File($this->path()), $this->id . ($extension === '' ? '' : ".$extension"));
        $this->delete();
        return $stored;
    }

    public function delete(): void
    {
        @unlink($this->path());
        @unlink(self::folder() . "/$this->id.json");
    }

    private static function folder(): string
    {
        $folder = storage_path('app/unscripted-uploads');
        is_dir($folder) || mkdir($folder, 0775, true);
        return $folder;
    }

    /** Clears uploads nobody took. */
    private static function sweep(): void
    {
        foreach (glob(self::folder() . '/*.json') ?: [] as $meta) {
            if (filemtime($meta) < time() - self::KEEP) {
                @unlink($meta);
                @unlink(substr($meta, 0, -5) . '.bin');
            }
        }
    }
}
