<?php

namespace Themosis\Core\Events;

use Illuminate\Broadcasting\PendingBroadcast;

trait Dispatchable
{
    /**
     * Dispatch the event with the given arguments.
     */
    public static function dispatch(...$arguments)
    {
        return event(new static(...$arguments));
    }

    /**
     * Dispatch the event with the given arguments if the given truth test passes.
     *
     * @param  bool  $boolean
     * @param  mixed  ...$arguments
     * @return mixed
     */
    public static function dispatchIf($boolean, ...$arguments)
    {
        if ($boolean) {
            return event(new static(...$arguments));
        }
    }

    /**
     * Dispatch the event with the given arguments unless the given truth test passes.
     *
     * @param  bool  $boolean
     * @param  mixed  ...$arguments
     * @return mixed
     */
    public static function dispatchUnless($boolean, ...$arguments)
    {
        if (! $boolean) {
            return event(new static(...$arguments));
        }
    }

    /**
     * Broadcast the event with the given arguments.
     *
     * @return PendingBroadcast
     */
    public static function broadcast(...$arguments)
    {
        return broadcast(new static(...$arguments));
    }
}
