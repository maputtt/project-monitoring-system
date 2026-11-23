<?php

use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\NoStore;
use App\Http\Middleware\RecordRequestMetric;
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
        // Di belakang Cloudflare: percaya X-Forwarded-* hanya dari IP Cloudflare (origin juga bisa dijangkau langsung, jadi '*' memungkinkan spoof IP/proto). Tanpa proxy (dev lokal) header diabaikan.
        // shortcut: daftar statis dari https://www.cloudflare.com/ips-v4 dan ips-v6 (per 2026-11), periksa ulang berkala dan saat pindah ke VPS/tanpa Cloudflare.
        $middleware->trustProxies(at: [
            '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
            '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
            '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
            '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
            '2a06:98c0::/29', '2c0f:f248::/32',
        ], headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO); // Host tidak dipercaya: Cloudflare tidak mengirimnya dan tautan reset password memakai host request.
        $middleware->prependToGroup('web', NoStore::class);
        $middleware->appendToGroup('web', RecordRequestMetric::class);
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
