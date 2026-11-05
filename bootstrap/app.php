<?php

use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\NoStore;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prependToGroup('web', NoStore::class);
        $middleware->alias(['active' => EnsureUserIsActive::class, 'role' => EnsureRole::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Filter query GET yang tidak valid: kembali ke URL bersih (tanpa query), bukan back(), agar URL buruk tidak menjadi "previous URL" dan redirect ke dirinya sendiri.
        $exceptions->render(fn (ValidationException $e, Request $request) => $request->isMethod('GET') && ! $request->expectsJson() && ! $e->redirectTo
            ? redirect($request->url())->withErrors($e->errors(), $e->errorBag)
            : null);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // Tautan unduh dokumen yang kedaluwarsa atau rusak: pesan yang jelas, bukan "tidak punya izin".
        $exceptions->render(fn (InvalidSignatureException $e, Request $request) => $request->routeIs('documents.download') && ! $request->expectsJson()
            ? response()->view('errors.page', ['code' => 403, 'heading' => 'Tautan unduh kedaluwarsa', 'message' => 'Tautan unduh berlaku 5 menit atau tidak valid. Muat ulang halaman dokumen, lalu klik Unduh lagi.'], 403)
            : null);
    })->create();
