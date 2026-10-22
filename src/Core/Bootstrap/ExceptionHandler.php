<?php

namespace Themosis\Core\Bootstrap;

use ErrorException;
use Exception;
use Illuminate\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Log\LogManager;
use Illuminate\Support\Env;
use Monolog\Handler\NullHandler;
use PHPUnit\Framework\TestCase;
use PHPUnit\Runner\ErrorHandler;
use PHPUnit\Runner\Version;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\ErrorHandler\Error\FatalError;
use Throwable;

class ExceptionHandler
{
    /**
     * The application instance.
     *
     * @var Application|null
     */
    protected static $app;

    /**
     * Reserved memory so that errors can be displayed properly on memory exhaustion.
     *
     * @var string|null
     */
    public static $reservedMemory;

    public function bootstrap(Application $app)
    {
        static::$reservedMemory = str_repeat('x', 32768);

        static::$app = $app;

        error_reporting(-1);

        set_error_handler($this->forwardsTo('handleError'));

        set_exception_handler($this->forwardsTo('handleException'));

        register_shutdown_function($this->forwardsTo('handleShutdown'));

        if (! $app->environment('testing')) {
            ini_set('display_errors', 'Off');
        }
    }

    /**
     * Report PHP deprecations, or convert PHP errors to ErrorException instances.
     *
     * @param  int  $level
     * @param  string  $message
     * @param  string  $file
     * @param  int  $line
     * @param  array  $context
     *
     * @throws ErrorException
     */
    public function handleError($level, $message, $file = '', $line = 0, $context = [])
    {
        if ($this->isDeprecation($level)) {
            $this->handleDeprecationError($message, $file, $line, $level);
        } elseif (error_reporting() & $level) {
            throw new ErrorException($message, 0, $level, $file, $line);
        }
    }

    /**
     * Reports a deprecation to the "deprecations" logger.
     *
     * @param  string  $message
     * @param  string  $file
     * @param  int  $line
     * @param  int  $level
     */
    public function handleDeprecationError($message, $file, $line, $level = E_DEPRECATED)
    {
        if ($this->shouldIgnoreDeprecationErrors()) {
            return;
        }

        if (! static::$app->bound('config')) {
            return;
        }

        try {
            $logger = static::$app->make(LogManager::class);

            $this->ensureDeprecationLoggerIsConfigured();

            $options = static::$app['config']->get('logging.deprecations') ?? [];

            with($logger->channel('deprecations'), function ($log) use ($message, $file, $line, $level, $options) {
                if ($options['trace'] ?? false) {
                    $log->warning((string) new ErrorException($message, 0, $level, $file, $line));
                } else {
                    $log->warning(sprintf(
                        '%s in %s on line %s',
                        $message,
                        $file,
                        $line,
                    ));
                }
            });
        } catch (Throwable) {
            return;
        }
    }

    /**
     * Reports a deprecation to the "deprecations" logger.
     *
     * @deprecated Use handleDeprecationError() instead.
     *
     * @param  string  $message
     * @param  string  $file
     * @param  int  $line
     */
    public function handleDeprecation($message, $file, $line)
    {
        $this->handleDeprecationError($message, $file, $line);
    }

    /**
     * Determine if deprecation errors should be ignored.
     *
     * @return bool
     */
    protected function shouldIgnoreDeprecationErrors()
    {
        return ! class_exists(LogManager::class)
            || ! static::$app->hasBeenBootstrapped()
            || (static::$app->runningUnitTests() && ! Env::get('LOG_DEPRECATIONS_WHILE_TESTING'));
    }

    /**
     * Ensure the "deprecations" logger is configured.
     */
    protected function ensureDeprecationLoggerIsConfigured()
    {
        $config = static::$app['config'];

        if ($config->get('logging.channels.deprecations')) {
            return;
        }

        $this->ensureNullLogDriverIsConfigured();

        if (is_array($options = $config->get('logging.deprecations'))) {
            $driver = $options['channel'] ?? 'null';
        } else {
            $driver = $options ?? 'null';
        }

        $config->set('logging.channels.deprecations', $config->get("logging.channels.{$driver}"));
    }

    /**
     * Ensure the "null" log driver is configured.
     */
    protected function ensureNullLogDriverIsConfigured()
    {
        $config = static::$app['config'];

        if ($config->get('logging.channels.null')) {
            return;
        }

        $config->set('logging.channels.null', [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ]);
    }

    /**
     * Handle an uncaught exception from the application.
     */
    public function handleException(Throwable $e)
    {
        static::$reservedMemory = null;

        try {
            $this->getExceptionHandler()->report($e);
        } catch (Exception) {
            $exceptionHandlerFailed = true;
        }

        if (static::$app->runningInConsole()) {
            $this->renderForConsole($e);

            if ($exceptionHandlerFailed ?? false) {
                exit(1);
            }
        } else {
            $this->renderHttpResponse($e);
        }
    }

    /**
     * Handle the PHP shutdown event.
     */
    public function handleShutdown()
    {
        static::$reservedMemory = null;

        if (! is_null($error = error_get_last()) && $this->isFatal($error['type'])) {
            $this->handleException($this->fatalExceptionFromError($error, 0));
        }
    }

    /**
     * Forward a method call to the given method if an application instance exists.
     *
     * @param  string  $method
     * @return callable
     */
    protected function forwardsTo($method)
    {
        return fn (...$arguments) => static::$app
            ? $this->{$method}(...$arguments)
            : false;
    }

    /**
     * Determine if the error level is a deprecation.
     *
     * @param  int  $level
     * @return bool
     */
    protected function isDeprecation($level)
    {
        return in_array($level, [E_DEPRECATED, E_USER_DEPRECATED]);
    }

    /**
     * Determine if the error type is fatal.
     *
     * @param  int  $type
     * @return bool
     */
    protected function isFatal($type)
    {
        return in_array($type, [E_COMPILE_ERROR, E_CORE_ERROR, E_ERROR, E_PARSE]);
    }

    /**
     * Create a new fatal exception instance from an error array.
     *
     * @param  int|null  $traceOffset
     * @return FatalError
     */
    protected function fatalExceptionFromError(array $error, $traceOffset = null)
    {
        return new FatalError(
            $error['message'],
            0,
            $error,
            $traceOffset,
        );
    }

    /**
     * Render an exception to the console.
     */
    protected function renderForConsole(Throwable $e)
    {
        $this->getExceptionHandler()->renderForConsole(new ConsoleOutput, $e);
    }

    /**
     * Render an exception as an HTTP Response and send it.
     */
    protected function renderHttpResponse(Throwable $e)
    {
        $this->getExceptionHandler()->render(static::$app['request'], $e)->send();
    }

    /**
     * Get an instance of the exception handler.
     *
     * @return ExceptionHandlerContract
     */
    protected function getExceptionHandler()
    {
        return static::$app->make(ExceptionHandlerContract::class);
    }

    /**
     * Clear the local application instance from memory.
     *
     * @return void
     */
    public static function forgetApp()
    {
        static::$app = null;
    }

    /**
     * Flush the bootstrapper's global state.
     *
     * @return void
     */
    public static function flushState(?TestCase $testCase = null)
    {
        if (is_null(static::$app)) {
            return;
        }

        static::flushHandlersState($testCase);

        static::$app = null;

        static::$reservedMemory = null;
    }

    /**
     * Flush the bootstrapper's global handlers state.
     *
     * @return void
     */
    public static function flushHandlersState(?TestCase $testCase = null)
    {
        while (get_exception_handler() !== null) {
            restore_exception_handler();
        }

        while (get_error_handler() !== null) {
            restore_error_handler();
        }

        if (class_exists(ErrorHandler::class)) {
            $instance = ErrorHandler::instance();

            if ((fn () => $this->enabled ?? false)->call($instance)) {
                $instance->disable();

                if (version_compare(Version::id(), '12.3.4', '>=')) {
                    $instance->enable($testCase);
                } else {
                    $instance->enable();
                }
            }
        }
    }
}
