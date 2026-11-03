<?php

namespace Unscripted\UiServer;

use Illuminate\Broadcasting\Channel;
use Illuminate\Support\Facades\Broadcast;
use Laravel\Reverb\Protocols\Pusher\EventDispatcher;

/**
 * Tells pages that something changed, over Reverb, on public channel `unscripted-live.<key>`:
 *
 * - `Live::changed('key')` reruns every page's `#[Live('key')]` loader. It only says which data
 *   changed, never what it is: the loader fetches it, with its checks.
 * - `Live::send('key', $data)` calls every page's `#[Live('key')]` browser method with `$data`,
 *   for what changes too often to refetch (a player's position). The channel is public: send
 *   only what anyone may see.
 *
 * Sent from a call over the socket, the caller's own page is left out: it knows already.
 */
final class Live
{
    /** The Reverb connection whose call SocketCalls is running, if it is. */
    public static ?object $connection = null;

    public static function changed(string $key): void
    {
        self::publish($key, 'changed', ['key' => $key]);
    }

    public static function send(string $key, mixed $data): void
    {
        self::publish($key, 'message', ['key' => $key, 'data' => $data]);
    }

    private static function publish(string $key, string $event, array $payload): void
    {
        $channel = "unscripted-live.$key";
        if (self::$connection !== null) {
            // In Reverb's own process, publish from here: broadcast() would ask this process over
            // HTTP and wait on itself.
            EventDispatcher::dispatch(self::$connection->app(), [
                'event' => $event,
                'channel' => $channel,
                'data' => json_encode($payload),
            ], self::$connection);
            return;
        }
        Broadcast::connection()->broadcast([new Channel($channel)], $event, $payload);
    }
}
