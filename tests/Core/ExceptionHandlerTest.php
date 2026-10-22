<?php

namespace Themosis\Tests\Core;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\ResponseFactory;
use Illuminate\Support\MessageBag;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Themosis\Core\Exceptions\Handler;

class ExceptionHandlerTest extends TestCase
{
    /**
     * @var Container
     */
    protected $container;

    /**
     * @var Handler
     */
    protected $handler;

    protected $request;

    /**
     * @var Repository
     */
    protected $config;

    protected function setUp(): void
    {
        $this->container = Container::setInstance(new Container);

        $this->request = $this->getMockBuilder('stdClass')
            ->setMethods(['expectsJson'])
            ->getMock();

        $this->config = $config = $this->getMockBuilder(Repository::class)
            ->setMethods(['get'])
            ->getMock();
        $this->container->singleton('config', function () use ($config) {
            return $config;
        });

        $viewFactory = $this->getMockBuilder(Factory::class)
            ->disableOriginalConstructor()
            ->getMock();
        $redirector = $this->getMockBuilder(Redirector::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->container->singleton(
            'Illuminate\Contracts\Routing\ResponseFactory',
            function () use ($viewFactory, $redirector) {
                return new ResponseFactory(
                    $viewFactory,
                    $redirector,
                );
            },
        );

        $this->handler = new Handler($this->container);
    }

    public function test_handler_report_exception_as_context()
    {
        $logger = $this->getMockBuilder(LoggerInterface::class)->getMock();
        $this->container->instance(LoggerInterface::class, $logger);

        $exception = new \RuntimeException('Exception message');

        $logger->expects($this->once())
            ->method('error')
            ->with(
                $this->equalTo('Exception message'),
                [
                    'exception' => $exception,
                ],
            );

        $this->handler->report($exception);
    }

    public function test_returns_json_with_stack_trace_when_ajax_request_and_debug_true()
    {
        $this->config->expects($this->once())
            ->method('get')
            ->with('app.debug', $this->equalTo(null))
            ->will($this->returnValue(true));
        $this->request->expects($this->once())->method('expectsJson')->will($this->returnValue(true));

        $response = $this->handler->render(
            $this->request,
            new \Exception('Custom error message'),
        )->getContent();

        $this->assertFalse(strpos($response, '<!DOCTYPE html>'));
        $this->assertTrue(strpos($response, '"message": "Custom error message"') !== false);
        $this->assertTrue(strpos($response, '"file":') !== false);
        $this->assertTrue(strpos($response, '"line":') !== false);
        $this->assertTrue(strpos($response, '"trace":') !== false);
    }

    public function test_returns_custom_response_when_exception_implement_responsable()
    {
        $response = $this->handler->render($this->request, new CustomException)->getContent();

        $this->assertSame('{"response":"Custom exception response"}', $response);
    }

    public function test_returns_json_without_stack_trace_when_ajax_request_and_debug_false_and_exception_message_is_masked()
    {
        $this->config->expects($this->once())
            ->method('get')
            ->with('app.debug', $this->equalTo(null))
            ->will($this->returnValue(false));
        $this->request->expects($this->once())->method('expectsJson')->will($this->returnValue(true));

        $response = $this->handler->render(
            $this->request,
            new \Exception('This error message should not be visible'),
        )->getContent();

        $this->assertTrue(strpos($response, '"message": "Server Error"') !== false);

        $this->assertFalse(strpos($response, '<!DOCTYPE html>'));
        $this->assertFalse(strpos($response, 'This error message should not be visible'));
        $this->assertFalse(strpos($response, '"file":'));
        $this->assertFalse(strpos($response, '"line":'));
        $this->assertFalse(strpos($response, '"trace":'));
    }

    public function test_returns_json_without_stack_trace_when_ajax_request_and_debug_false_and_http_exception_is_shown()
    {
        $this->config->expects($this->once())
            ->method('get')
            ->with('app.debug', $this->equalTo(null))
            ->will($this->returnValue(false));
        $this->request->expects($this->once())->method('expectsJson')->will($this->returnValue(true));

        $response = $this->handler->render(
            $this->request,
            new HttpException(
                403,
                'Custom error message',
            ),
        )->getContent();

        $this->assertTrue(strpos($response, '"message": "Custom error message"') !== false);
        $this->assertFalse(strpos($response, '<!DOCTYPE html>'));
        $this->assertFalse(strpos($response, '"message": "Server Error"'));
        $this->assertFalse(strpos($response, '"file":'));
        $this->assertFalse(strpos($response, '"line":'));
        $this->assertFalse(strpos($response, '"trace":'));
    }

    public function test_returns_json_without_stack_trace_when_ajax_request_and_debug_false_and_access_denied_http_exception_error_is_shown()
    {
        $this->config->expects($this->once())
            ->method('get')
            ->with('app.debug', $this->equalTo(null))
            ->will($this->returnValue(false));
        $this->request->expects($this->once())->method('expectsJson')->will($this->returnValue(true));

        $response = $this->handler->render(
            $this->request,
            new AccessDeniedHttpException('Custom error message'),
        )->getContent();

        $this->assertTrue(strpos($response, '"message": "Custom error message"') !== false);
        $this->assertFalse(strpos($response, '<!DOCTYPE html>'));
        $this->assertFalse(strpos($response, '"message": "Server Error"'));
        $this->assertFalse(strpos($response, '"file":'));
        $this->assertFalse(strpos($response, '"line":'));
        $this->assertFalse(strpos($response, '"trace":'));
    }

    public function test_validate_file_method()
    {
        $argumentExpected = ['input' => 'My input value'];
        $argumentActual = null;

        $this->container->singleton('redirect', function () use (&$argumentActual) {
            $redirector = $this->createMock(Redirector::class);

            $redirector->expects($this->once())
                ->method('to')
                ->willReturn($responser = $this->createMock(RedirectResponse::class));

            $responser->expects($this->once())
                ->method('withInput')
                ->with($this->callback(function ($argument) use (&$argumentActual) {
                    $argumentActual = $argument;

                    return true;
                }))
                ->willReturn($responser);

            $responser->expects($this->once())
                ->method('withErrors')
                ->willReturn($responser);

            return $redirector;
        });

        $file = $this->createMock(UploadedFile::class);
        $file->method('getPathname')->willReturn('photo.jpg');
        $file->method('getClientOriginalName')->willReturn('photo.jpg');
        $file->method('getClientMimeType')->willReturn(null);
        $file->method('getError')->willReturn(null);

        $request = Request::create('/', 'POST', $argumentExpected, [], ['photo' => $file]);

        $validator = $this->createMock(Validator::class);
        $validator->method('errors')->willReturn(new MessageBag(['error' => 'My custom validation exception']));

        $validationException = new ValidationException($validator);
        $validationException->redirectTo = '/';

        $this->handler->render($request, $validationException);

        $this->assertEquals($argumentExpected, $argumentActual);
    }
}

class CustomException extends \Exception implements Responsable
{
    public function toResponse($request)
    {
        return response()->json(['response' => 'Custom exception response']);
    }
}
