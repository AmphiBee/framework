<?php

namespace Themosis\Taxonomy;

use Illuminate\View\Factory;
use Themosis\Hook\IHook;
use Themosis\Taxonomy\Contracts\TaxonomyInterface;

class TaxonomyFieldFactory
{
    /**
     * @var Factory
     */
    private $factory;

    /**
     * @var \Illuminate\Contracts\Validation\Factory
     */
    private $validator;

    /**
     * @var IHook
     */
    private $action;

    public function __construct(
        Factory $viewFactory,
        \Illuminate\Contracts\Validation\Factory $validator,
        IHook $action,
    ) {
        $this->factory = $viewFactory;
        $this->validator = $validator;
        $this->action = $action;
    }

    /**
     * Create a new TaxonomyField instance.
     *
     *
     * @return TaxonomyField
     */
    public function make(TaxonomyInterface $taxonomy, array $options = [])
    {
        $options = array_merge([
            'theme' => 'themosis.taxonomy',
            'prefix' => 'th_',
        ], $options);

        return new TaxonomyField(
            $taxonomy,
            new TaxonomyFieldRepository,
            $this->factory,
            $this->validator,
            $this->action,
            $options,
        );
    }
}
