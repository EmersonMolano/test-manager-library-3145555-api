<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(\App\Http\Middleware\Cors::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (\Illuminate\Validation\ValidationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'code' => 'VALIDATION_ERROR',
                'message' => 'La solicitud no es válida.',
                'details' => collect($exception->errors())->flatMap(
                    fn (array $issues, string $field) => collect($issues)->map(
                        fn (string $issue) => ['field' => $field, 'issue' => $issue],
                    ),
                )->values(),
            ], 422);
        });

        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'code' => $exception->getStatusCode() === 403 ? 'FORBIDDEN_ROLE' : 'NOT_FOUND',
                'message' => $exception->getStatusCode() === 403 ? 'No tienes permisos para realizar esta operación.' : 'El recurso no existe.',
                'details' => [],
            ], $exception->getStatusCode());
        });
    })->create();
