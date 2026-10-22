<?php

namespace Themosis\Core\Support;

use Themosis\Core\Bus\Dispatchable;
use Themosis\Core\Bus\PendingChain;
use Themosis\Core\Bus\PendingClosureDispatch;
use Themosis\Core\Bus\PendingDispatch;
use Themosis\Core\Precognition;
use Themosis\Core\Queue\InteractsWithUniqueJobs;

/**
 * Some illuminate/* packages directly reference classes and traits from
 * "Illuminate\Foundation" (only shipped with laravel/framework), for example
 * Illuminate\Queue\CallQueuedClosure, Illuminate\Bus\Dispatcher::chain() or
 * Illuminate\Broadcasting\AnonymousEvent. This autoloader maps those
 * references to their Themosis equivalents when laravel/framework is not installed.
 */
class FoundationAliases
{
    /**
     * Illuminate\Foundation classes and their Themosis equivalent.
     *
     * @var array<string, string>
     */
    protected static $aliases = [
        'Illuminate\Foundation\Bus\Dispatchable' => Dispatchable::class,
        'Illuminate\Foundation\Bus\PendingChain' => PendingChain::class,
        'Illuminate\Foundation\Bus\PendingClosureDispatch' => PendingClosureDispatch::class,
        'Illuminate\Foundation\Bus\PendingDispatch' => PendingDispatch::class,
        'Illuminate\Foundation\Events\Dispatchable' => \Themosis\Core\Events\Dispatchable::class,
        'Illuminate\Foundation\Precognition' => Precognition::class,
        'Illuminate\Foundation\Queue\InteractsWithUniqueJobs' => InteractsWithUniqueJobs::class,
    ];

    /**
     * Indicates if the autoloader has been registered.
     *
     * @var bool
     */
    protected static $registered = false;

    /**
     * Register the autoloader (appended to the stack so that
     * laravel/framework classes always take precedence if installed).
     */
    public static function register(): void
    {
        if (static::$registered) {
            return;
        }

        spl_autoload_register([static::class, 'load']);

        static::$registered = true;
    }

    /**
     * Alias the requested Illuminate\Foundation class if Themosis provides it.
     *
     * @param  string  $class
     * @return bool|null
     */
    public static function load($class)
    {
        if (! isset(static::$aliases[$class])) {
            return null;
        }

        return class_alias(static::$aliases[$class], $class);
    }

    /**
     * Get the registered aliases.
     *
     * @return array<string, string>
     */
    public static function getAliases(): array
    {
        return static::$aliases;
    }
}
