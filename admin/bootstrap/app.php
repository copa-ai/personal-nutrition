<?php

use App\Http\Middleware\AutoLogin;
use App\Http\Middleware\ChatInterno;
use App\Services\Chat\ErrorChat;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware(ChatInterno::class)->prefix('interno/chat')->group(base_path('routes/interno.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // El login automatico del panel debe ir ANTES de Authenticate (que se ordena el primero por ser AuthenticatesRequests).
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: AutoLogin::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport(ErrorChat::class);
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('interno/chat/*')) {
                return null;
            }
            if ($e instanceof ErrorChat) {
                return $e->respuesta();
            }
            if ($e instanceof NotFoundHttpException) {
                return ErrorChat::notFound('No existe.')->respuesta();
            }
            if ($e instanceof HttpExceptionInterface) {
                return (new ErrorChat('bad_request', 'Petición no válida.', $e->getStatusCode()))->respuesta();
            }
            report($e);

            return ErrorChat::serviceError()->respuesta();
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
