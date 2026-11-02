<?php

namespace Unscripted\UiServer;

use Illuminate\Broadcasting\Channel;
use Illuminate\Support\Facades\Broadcast;
use Laravel\Reverb\Protocols\Pusher\EventDispatcher;

/**
 * Tells every page with a `#[Live('key')]` loader to run it again: call `Live::changed('key')`
 * after changing what the loader reads. Pages hear it on public channel `unscripted-live.<key>`,
 * so only say which data changed, never what it is: the loader fetches it, with its checks.
 */
final class Live
{
    /** The Reverb connection whose call SocketCalls is running, if it is. */
    public static ?object $connection = null;

    public static function changed(string $key): void
    {
        $channel = "unscripted-live.$key";
        if (self::$connection !== null) {
            // In Reverb's own process, publish from here: broadcast() would ask this process over
            // HTTP and wait on itself. The caller's page is left out: it reloads after its call.
            EventDispatcher::dispatch(self::$connection->app(), [
                'event' => 'changed',
                'channel' => $channel,
                'data' => json_encode(['key' => $key]),
            ], self::$connection);
            return;
        }
        Broadcast::connection()->broadcast([new Channel($channel)], 'changed', ['key' => $key]);
    }
}
