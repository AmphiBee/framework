<?php

namespace Themosis\Core\Events;

class LocaleUpdated
{
    /**
     * The updated application locale.
     *
     * @var string
     */
    public $locale;

    /**
     * The previous application locale.
     *
     * @var string|null
     */
    public $previousLocale;

    public function __construct($locale, $previousLocale = null)
    {
        $this->locale = $locale;
        $this->previousLocale = $previousLocale;
    }
}
