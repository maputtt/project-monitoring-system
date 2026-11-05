<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Halaman berisi data finansial tidak boleh tersimpan di cache browser (tombol Back setelah logout). */
class NoStore
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // headers->set (bukan withHeaders) agar juga berlaku untuk BinaryFileResponse/StreamedResponse.
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');

        return $response;
    }
}
