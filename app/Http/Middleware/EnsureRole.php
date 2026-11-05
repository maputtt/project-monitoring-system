<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Pemakaian di route: ->middleware('role:STAFF_PIC,MANAGER_GM'). Role lain mendapat 403. */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        abort_unless(in_array($request->user()?->role->value, $roles, true), 403, 'Anda tidak berhak melakukan aksi ini.');

        return $next($request);
    }
}
