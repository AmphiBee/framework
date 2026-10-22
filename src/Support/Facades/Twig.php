<?php

namespace Themosis\Support\Facades;

use Illuminate\Support\Facades\Facade;
use Twig\Environment;

/**
 * @see Environment
 */
class Twig extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'twig';
    }
}
