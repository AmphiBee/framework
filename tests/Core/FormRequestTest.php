<?php

namespace Themosis\Tests\Core;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Container\Container;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Themosis\Core\Http\FormRequest;

class FormRequestTest extends TestCase
{
    /**
     * @var array
     */
    protected $mocks = [];

    public function test_validated_method_returns_validated_data()
    {
        $request = $this->createRequest(['name' => 'something', 'with' => 'brush']);

        $request->validateResolved();

        $this->assertEquals(['name' => 'something'], $request->validated());
    }

    public function test_validate_throws_when_validation_fails()
    {
        $this->expectException(ValidationException::class);

        $request = $this->createRequest(['no' => 'name']);

        $this->mocks['redirect']->expects($this->any())->method('withInput');
        $this->mocks['redirect']->expects($this->any())->method('withErrors');

        $request->validateResolved();
    }

    public function test_validate_method_throws_when_authorization_fails()
    {
        $this->expectException(AuthorizationException::class);

        $this->createRequest([], CoreTestFormRequestForbiddenStub::class)->validateResolved();
    }

    public function test_prepare_for_validation_runs_before_validation()
    {
        $request = $this->createRequest([], CoreTestFormRequestHooks::class);
        $request->validateResolved();

        $this->assertEquals(['name' => 'Themosis'], $request->validated());
    }

    protected function createRequest($payload = [], $class = CoreTestFormRequestStub::class)
    {
        $container = tap(new Container, function ($container) {
            $container->instance(
                Factory::class,
                $this->createValidationFactory($container),
            );
        });

        $request = $class::create('/', 'GET', $payload);

        return $request->setContainer($container)
            ->setRedirector($this->createRedirector($request));
    }

    /**
     * Create a validation factory.
     *
     * @param  Container  $container
     * @return Factory
     */
    protected function createValidationFactory($container)
    {
        $translator = new Translator(
            new ArrayLoader,
            'en_US',
        );

        return new Factory($translator, $container);
    }

    /**
     * Create a mock redirector.
     *
     * @param  Request  $request
     * @return Redirector
     */
    protected function createRedirector($request)
    {
        $redirector = $this->mocks['redirector'] = $this->getMockBuilder(Redirector::class)
            ->setConstructorArgs([$generator = $this->createUrlGenerator()])
            ->setMethods(['getUrlGenerator', 'to'])
            ->getMock();

        $redirector->expects($this->any())->method('getUrlGenerator')->willReturn($generator);

        $redirector->expects($this->any())->method('to')->willReturn($this->createRedirectResponse());

        $generator->expects($this->any())->method('previous')->willReturn('previous');

        return $redirector;
    }

    /**
     * Create a URL generator.
     *
     * @return MockObject
     */
    protected function createUrlGenerator()
    {
        return $this->mocks['generator'] = $this->getMockBuilder(UrlGenerator::class)
            ->disableOriginalConstructor()
            ->setMethods(['previous'])
            ->getMock();
    }

    /**
     * Create a redirect response.
     *
     * @return MockObject
     */
    protected function createRedirectResponse()
    {
        return $this->mocks['redirect'] = $this->getMockBuilder(RedirectResponse::class)
            ->disableOriginalConstructor()
            ->setMethods(['withInput', 'WithErrors'])
            ->getMock();
    }
}

class CoreTestFormRequestStub extends FormRequest
{
    public function rules()
    {
        return ['name' => 'required'];
    }

    public function authorize()
    {
        return true;
    }
}

class CoreTestFormRequestForbiddenStub extends FormRequest
{
    public function rules()
    {
        return [];
    }

    public function authorize()
    {
        return false;
    }
}

class CoreTestFormRequestHooks extends FormRequest
{
    public function rules()
    {
        return ['name' => 'required'];
    }

    public function authorize()
    {
        return true;
    }

    public function prepareForValidation()
    {
        $this->replace(['name' => 'Themosis']);
    }
}
