<?php

namespace Unscripted\UiServer;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Reverb\Contracts\Connection;
use Laravel\Reverb\Events\MessageReceived;
use Laravel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Server calls over Reverb's WebSocket (`unscripted.transport` = 'reverb'). Runs in the Reverb
 * process (`php artisan reverb:start`), which hands it every message; it answers
 * `unscripted:call` ones on the same connection:
 *
 *   {"event": "unscripted:call", "channel": "private-unscripted.<user id>", "data": {"id": 1, "calls": [...]}}
 *   => {"event": "unscripted:result", "data": "{\"id\": 1, \"status\": 200, \"results\": [...]}"}
 *
 * No middleware runs per message. The user is the one the connection's private channel was
 * signed for (by /broadcasting/auth, with the session); with no channel, a guest.
 */
final class SocketCalls
{
    public function __construct(private Components $components) {}

    public function handle(MessageReceived $event): void
    {
        $message = json_decode($event->message, true);
        if (!is_array($message) || ($message['event'] ?? null) !== 'unscripted:call') {
            return;
        }
        $data = $message['data'] ?? null;
        $data = is_string($data) ? json_decode($data, true) : $data;

        $guard = Auth::guard();
        $guard->forgetUser();
        try {
            $user = $this->user($event->connection, (string) ($message['channel'] ?? ''));
            if ($user !== null) {
                $guard->setUser($user);
            }
            Live::$connection = $event->connection;
            $reply = ['status' => 200, 'results' => $this->components->run($data['calls'] ?? null)];
        } catch (Throwable $e) {
            $reply = self::failure($e) ?? (function () use ($e) {
                report($e);
                return ['status' => 500, 'message' => config('app.debug') ? $e->getMessage() : 'Server Error'];
            })();
        } finally {
            // The process serves everyone: nobody's login outlives their message.
            $guard->forgetUser();
            Live::$connection = null;
        }

        $event->connection->send(json_encode([
            'event' => 'unscripted:result',
            'data' => json_encode(['id' => $data['id'] ?? null, ...$reply], JSON_PARTIAL_OUTPUT_ON_ERROR),
        ]));
    }

    /** The user whose private channel `$channel` is, if the connection was let into it. */
    private function user(Connection $connection, string $channel): ?Authenticatable
    {
        if (!preg_match('/^private-unscripted\.(.+)$/', $channel, $m)) {
            return null;
        }
        $member = app(ChannelManager::class)->for($connection->app())->find($channel)?->find($connection);
        return $member === null ? null : Auth::guard()->getProvider()->retrieveById($m[1]);
    }

    /** An exception as the HTTP route's JSON would have it: a status, a message, `errors`. Null
     * for one that's a bug rather than an answer (a 500). */
    public static function failure(Throwable $e): ?array
    {
        return match (true) {
            $e instanceof UnknownCall => ['status' => 404, 'message' => $e->getMessage()],
            $e instanceof BadArguments => ['status' => 422, 'message' => $e->getMessage()],
            $e instanceof ValidationException => ['status' => 422, 'message' => $e->getMessage(), 'errors' => $e->errors()],
            $e instanceof AuthorizationException => ['status' => 403, 'message' => $e->getMessage()],
            $e instanceof ModelNotFoundException => ['status' => 404, 'message' => 'Not Found'],
            $e instanceof HttpExceptionInterface => ['status' => $e->getStatusCode(), 'message' => $e->getMessage()],
            default => null,
        };
    }
}
