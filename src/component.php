<?php
/*
 * The server's stand-ins for @unscripted/ui's classes: enough for a component's PHP block to
 * load here, so its #[Server] methods can be called. The browser has the real ones, in ui.php.
 */

namespace Unscripted\Ui;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
final class State {}

#[Attribute(Attribute::TARGET_METHOD)]
final class Server {}

#[Attribute(Attribute::TARGET_METHOD)]
final class Watch
{
    public array $properties;

    public function __construct(string|array $properties, public readonly int $debounce = 0)
    {
        $this->properties = (array) $properties;
    }
}

abstract class Component {}
