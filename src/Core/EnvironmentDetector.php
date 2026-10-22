<?php

namespace Themosis\Core;

use Closure;

class EnvironmentDetector
{
    /**
     * Detect application current environment.
     *
     * @param  array|null  $consoleArgs
     * @return string
     */
    public function detect(Closure $callback, $consoleArgs = null)
    {
        if ($consoleArgs) {
            return $this->detectConsoleEnvironment($callback, $consoleArgs);
        }

        return $this->detectWebEnvironment($callback);
    }

    /**
     * Set the application environment for a web request.
     *
     *
     * @return string
     */
    protected function detectWebEnvironment(Closure $callback)
    {
        return call_user_func($callback);
    }

    /**
     * Set the application environment for a command-line request.
     *
     *
     * @return string
     */
    protected function detectConsoleEnvironment(Closure $callback, array $args)
    {
        if (! is_null($value = $this->getEnvironmentArgument($args))) {
            return $value;
        }

        return $this->detectWebEnvironment($callback);
    }

    /**
     * Get the environment argument from the console.
     *
     *
     * @return string|null
     */
    protected function getEnvironmentArgument(array $args)
    {
        foreach ($args as $i => $value) {
            if ($value === '--env') {
                return $args[$i + 1] ?? null;
            }

            if (str_starts_with($value, '--env=')) {
                return head(array_slice(explode('=', $value), 1));
            }
        }

        return null;
    }
}
