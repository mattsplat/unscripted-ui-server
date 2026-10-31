<?php

namespace Unscripted\UiServer;

use Closure;
use Error;
use ParseError;
use PhpToken;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Throwable;
use Unscripted\Ui\Component;
use Unscripted\Ui\Server;

/**
 * The app's components, `<name>.blade.php` files in one folder. The browser gets each with its
 * #[Server] methods kept back, and calls them through call().
 */
final class Components
{
    /** Each loaded component's class and the hash of the source it came from, by name. */
    private array $classes = [];

    /** `$cache` holds each component's PHP block, compiled to a file of its own
     * (unscripted-<name>-<hash>.php, as Laravel compiles views), so opcache keeps it. */
    public function __construct(
        private string $folder,
        private string $cache,
        /** Checks `#[Server(can: ...)]`: called with the ability and the call's arguments, throws if it's denied. */
        private ?Closure $authorize = null,
    ) {}

    /** The file as the browser gets it, or null if there's no component `$name`. */
    public function browserSource(string $name): ?string
    {
        $path = $this->path($name);
        return $path === null ? null : self::strip(file_get_contents($path), $name);
    }

    /**
     * `$source` with each public #[Server] method's body swapped for a stub that returns a
     * ServerCall, and private and protected #[Server] methods (server-only helpers) removed.
     * Lines are kept, so the browser's line numbers are the file's.
     */
    public static function strip(string $source, string $name): string
    {
        $tokens = PhpToken::tokenize($source);
        $out = '';
        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            $method = $tokens[$i]->is(T_ATTRIBUTE) ? self::method($tokens, $i) : null;
            if ($method === null || !$method['server']) {
                $out .= $tokens[$i]->text;
                continue;
            }
            $where = "$name.blade.php:{$tokens[$i]->line}";
            if (!$method['static']) {
                throw new Error("#[Server] method {$method['name']} must be static, in $where: it gets only its arguments, not the component's state");
            }
            $stub = '';
            if ($method['public']) {
                $stub = "{$method['attributes']} public static function {$method['name']}{$method['params']}: \\Unscripted\\Ui\\ServerCall"
                    . ' { return new \Unscripted\Ui\ServerCall(' . var_export($name, true) . ", '{$method['name']}', func_get_args()); }";
            }
            $original = implode('', array_map(fn($t) => $t->text, array_slice($tokens, $i, $method['end'] - $i + 1)));
            $out .= $stub . str_repeat("\n", max(0, substr_count($original, "\n") - substr_count($stub, "\n")));
            $i = $method['end'];
        }
        return $out;
    }

    /**
     * The method whose attributes start at `$tokens[$start]`, or null if they're not a method's
     * (a parameter's, a property's).
     */
    private static function method(array $tokens, int $start): ?array
    {
        $attributes = [];
        $server = $static = false;
        $public = true;
        for ($i = $start, $n = count($tokens); $i < $n; $i++) {
            $token = $tokens[$i];
            if ($token->isIgnorable()) {
                continue;
            }
            if ($token->is(T_ATTRIBUTE)) {
                // To the group's closing ]. Attribute names are at depth 1, outside parentheses.
                $group = '';
                for ($depth = 0, $parens = 0; $i < $n; $i++) {
                    $t = $tokens[$i];
                    $group .= $t->text;
                    $depth += match (true) { $t->is([T_ATTRIBUTE, '[']) => 1, $t->is(']') => -1, default => 0 };
                    $parens += match (true) { $t->is('(') => 1, $t->is(')') => -1, default => 0 };
                    if ($depth === 1 && $parens === 0 && $t->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])
                        && ($t->text === 'Server' || str_ends_with($t->text, '\Server'))) {
                        $server = true;
                    }
                    if ($depth === 0) {
                        break;
                    }
                }
                $attributes[] = $group;
                continue;
            }
            if ($token->is([T_PRIVATE, T_PROTECTED])) {
                $public = false;
            } elseif ($token->is(T_STATIC)) {
                $static = true;
            } elseif ($token->is(T_FUNCTION)) {
                break;
            } elseif (!$token->is([T_PUBLIC, T_FINAL, T_ABSTRACT])) {
                return null;
            }
        }

        // function name(params): type { body }
        $name = null;
        $params = '';
        for ($i++; $i < $n && $name === null; $i++) {
            if ($tokens[$i]->is(T_STRING)) {
                $name = $tokens[$i]->text;
            }
        }
        for ($depth = 0; $i < $n; $i++) {
            $params .= $tokens[$i]->text;
            $depth += match (true) { $tokens[$i]->is('(') => 1, $tokens[$i]->is(')') => -1, default => 0 };
            if ($depth === 0 && $tokens[$i]->is(')')) {
                break;
            }
        }
        $params = ltrim($params);
        for ($i++; $i < $n && !$tokens[$i]->is(['{', ';']); $i++);
        if ($i < $n && $tokens[$i]->is('{')) {
            for ($depth = 0; $i < $n; $i++) {
                $depth += match (true) {
                    $tokens[$i]->is(['{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES]) => 1,
                    $tokens[$i]->is('}') => -1,
                    default => 0,
                };
                if ($depth === 0) {
                    break;
                }
            }
        }
        if ($name === null || $i >= $n) {
            return null;
        }
        return [
            'name' => $name,
            'params' => $params,
            'attributes' => implode(' ', $attributes),
            'server' => $server,
            'static' => $static,
            'public' => $public,
            'end' => $i,
        ];
    }

    /** Calls component `$name`'s #[Server] method `$method`. Throws UnknownCall if there's no
     * such method, or BadArguments if `$args` don't fit it. */
    public function call(string $name, string $method, array $args): mixed
    {
        [$class, $path, $compiled] = $this->load($name);
        if (!method_exists($class, $method)) {
            throw new UnknownCall("$name has no server method $method");
        }
        $reflection = new ReflectionMethod($class, $method);
        $server = $reflection->getAttributes(Server::class)[0] ?? null;
        if ($server === null || !$reflection->isPublic() || !$reflection->isStatic()) {
            throw new UnknownCall("$name has no server method $method");
        }
        $can = $server->newInstance()->can;
        if ($can !== null) {
            if ($this->authorize === null) {
                throw new Error("$name::$method has #[Server(can: '$can')], but nothing to check it with");
            }
            ($this->authorize)($can, array_values($args));
        }
        try {
            return $reflection->invokeArgs(null, array_values($args));
        } catch (\TypeError $e) {
            // The arguments' fault, not a TypeError inside the method: PHP reports both from the
            // method's frame, but the arguments' at the line it's declared on. The anonymous
            // class's name (the file's path) isn't for the browser.
            $trace = $e->getTrace();
            if (($trace[0]['function'] ?? null) === $method && ($trace[1]['function'] ?? null) === 'invokeArgs'
                && $e->getLine() === $reflection->getStartLine()
                && preg_match('/^(?:.*?\(\): (Argument #.*)|Too few arguments to function .*?\(\), (.*))$/s', $e->getMessage(), $m)) {
                throw new BadArguments("$name::$method(): " . ($m[1] !== '' ? $m[1] : "too few arguments, {$m[2]}"), 0, $e);
            }
            throw $this->located($e, $compiled, $path);
        } catch (Throwable $e) {
            throw $this->located($e, $compiled, $path);
        }
    }

    /** An error from the compiled block, said to be from the component's file: the line is
     * the same. */
    private function located(Throwable $e, string $compiled, string $path): Throwable
    {
        if ($e->getFile() === $compiled) {
            new ReflectionProperty($e, 'file')->setValue($e, $path);
        }
        return $e;
    }

    private function path(string $name): ?string
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $name)) {
            return null;
        }
        $path = "{$this->folder}/$name.blade.php";
        return is_file($path) ? $path : null;
    }

    /** The class component `$name`'s PHP block makes (as @unscripted/ui's blade() does), its
     * file and the compiled file. */
    private function load(string $name): array
    {
        $path = $this->path($name) ?? throw new UnknownCall("there's no component $name");
        $source = file_get_contents($path);
        $hash = md5($source);
        if (($this->classes[$name][0] ?? null) === $hash) {
            return $this->classes[$name][1];
        }
        $end = str_starts_with(ltrim($source), '<?php') ? strpos($source, '?>') : false;
        if ($end === false || !preg_match('/\bnew\s+class\b/', $source, $m, PREG_OFFSET_CAPTURE) || $m[0][1] > $end) {
            throw new UnknownCall("$name has no server methods: it has no PHP block making its class");
        }
        // The block alone, returning its object; a comment on its first line so the lines stay
        // the file's.
        $code = substr_replace(substr($source, 0, $end + 2), 'return new class', $m[0][1], strlen($m[0][0]));
        $code = preg_replace('/<\?php/', "<?php /* $path, compiled by unscripted/ui-server */", $code, 1);

        $compiled = "{$this->cache}/unscripted-$name-$hash.php";
        if (!is_file($compiled)) {
            is_dir($this->cache) || mkdir($this->cache, 0775, true);
            foreach (glob("{$this->cache}/unscripted-$name-*.php") as $old) {
                unlink($old);
            }
            $temporary = tempnam($this->cache, $name);
            file_put_contents($temporary, $code);
            rename($temporary, $compiled);
        }
        try {
            $component = (static fn() => require $compiled)();
        } catch (ParseError $e) {
            throw new Error("{$e->getMessage()} in $path:{$e->getLine()}", 0, $e);
        }
        if (!$component instanceof Component) {
            throw new Error("$path: the PHP block's class must extend " . Component::class);
        }
        $loaded = [$component::class, $path, realpath($compiled)]; // as PHP names it in errors
        $this->classes[$name] = [$hash, $loaded];
        return $loaded;
    }
}

/** A call to something that isn't a component's #[Server] method. */
final class UnknownCall extends RuntimeException {}

/** A server method called with arguments that don't fit it. */
final class BadArguments extends RuntimeException {}
