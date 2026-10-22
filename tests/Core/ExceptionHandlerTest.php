<?php

namespace Themosis\Tests\Core;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\ResponseFactory;
use Illuminate\Support\Lottery;
use Illuminate\Support\MessageBag;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
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

        $this->request = $this->getMockBuilder(ExceptionHandlerTestRequest::class)
            ->onlyMethods(['expectsJson'])
            ->getMock();

        $this->config = $config = $this->getMockBuilder(Repository::class)
            ->onlyMethods(['get'])
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
            ->willReturn(true);
        $this->request->expects($this->once())->method('expectsJson')->willReturn(true);

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
            ->willReturn(false);
        $this->request->expects($this->once())->method('expectsJson')->willReturn(true);

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
            ->willReturn(false);
        $this->request->expects($this->once())->method('expectsJson')->willReturn(true);

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
            ->willReturn(false);
        $this->request->expects($this->once())->method('expectsJson')->willReturn(true);

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

    public function test_shouldnt_report_exceptions_implementing_shouldnt_report()
    {
        $this->assertFalse($this->handler->shouldReport(new UnreportableException));
        $this->assertTrue($this->handler->shouldReport(new \RuntimeException));
    }

    public function test_dont_report_when_callback()
    {
        $this->handler->dontReportWhen(fn (\Throwable $e) => $e->getMessage() === 'ignored');

        $this->assertFalse($this->handler->shouldReport(new \RuntimeException('ignored')));
        $this->assertTrue($this->handler->shouldReport(new \RuntimeException('reported')));
    }

    public function test_ignore_and_stop_ignoring_accept_arrays()
    {
        $this->handler->ignore([\RuntimeException::class, \LogicException::class]);

        $this->assertFalse($this->handler->shouldReport(new \RuntimeException));
        $this->assertFalse($this->handler->shouldReport(new \LogicException));

        $this->handler->stopIgnoring([\RuntimeException::class, \LogicException::class]);

        $this->assertTrue($this->handler->shouldReport(new \RuntimeException));
        $this->assertTrue($this->handler->shouldReport(new \LogicException));
    }

    public function test_dont_report_duplicates()
    {
        $logger = $this->getMockBuilder(LoggerInterface::class)->getMock();
        $this->container->instance(LoggerInterface::class, $logger);

        $logger->expects($this->once())->method('error');

        $exception = new \RuntimeException('Exception message');

        $this->handler->dontReportDuplicates();
        $this->handler->report($exception);
        $this->handler->report($exception);
    }

    public function test_throttle_using_lottery_can_skip_reporting()
    {
        $logger = $this->getMockBuilder(LoggerInterface::class)->getMock();
        $this->container->instance(LoggerInterface::class, $logger);

        $logger->expects($this->never())->method('error');

        $this->handler->throttleUsing(fn (\RuntimeException $e) => Lottery::odds(0, 1));
        $this->handler->report(new \RuntimeException('Exception message'));
    }

    public function test_build_context_using_callbacks()
    {
        $logger = $this->getMockBuilder(LoggerInterface::class)->getMock();
        $this->container->instance(LoggerInterface::class, $logger);

        $exception = new \RuntimeException('Exception message');

        $logger->expects($this->once())
            ->method('error')
            ->with('Exception message', ['foo' => 'bar', 'exception' => $exception]);

        $this->handler->buildContextUsing(fn (\Throwable $e) => ['foo' => 'bar']);
        $this->handler->report($exception);
    }

    public function test_reportable_callback_supports_union_types()
    {
        $logger = $this->getMockBuilder(LoggerInterface::class)->getMock();
        $this->container->instance(LoggerInterface::class, $logger);
        $logger->expects($this->never())->method('error');

        $reported = [];

        $this->handler->reportable(function (\InvalidArgumentException|\LogicException $e) use (&$reported) {
            $reported[] = $e;

            return false;
        });

        $this->handler->report(new \LogicException);

        $this->assertCount(1, $reported);
    }

    public function test_request_exceptions_are_rendered_as_bad_request_and_not_reported()
    {
        $this->request->expects($this->once())->method('expectsJson')->willReturn(true);

        $exception = new SuspiciousOperationException('Invalid host');

        $this->assertFalse($this->handler->shouldReport($exception));

        $response = $this->handler->render($this->request, $exception);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('"message": "Bad request."', $response->getContent());
    }

    public function test_respond_using_and_should_render_json_when()
    {
        $this->request->expects($this->never())->method('expectsJson');

        $this->handler->shouldRenderJsonWhen(fn ($request, \Throwable $e) => true);
        $this->handler->respondUsing(function ($response, \Throwable $e, $request) {
            $response->headers->set('X-Finalized', 'yes');

            return $response;
        });

        $response = $this->handler->render($this->request, new HttpException(403, 'Forbidden'));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('yes', $response->headers->get('X-Finalized'));
        $this->assertStringContainsString('"message": "Forbidden"', $response->getContent());
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
        $file->method('getClientMimeType')->willReturn('image/jpeg');
        $file->method('getError')->willReturn(UPLOAD_ERR_OK);

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

class UnreportableException extends \Exception implements ShouldntReport
{
    //
}

class ExceptionHandlerTestRequest
{
    public function expectsJson()
    {
        return false;
    }
}
