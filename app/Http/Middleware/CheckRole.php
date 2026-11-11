<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  ...$roles
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if (!$request->user()) {
            return response()->json([
                'message' => 'No autenticado'
            ], 401);
        }

        foreach ($roles as $role) {
            if ($request->user()->hasRole($role)) {
                return $next($request);
            }
        }

        // Las rutas web (Inertia) necesitan la página 403 normal, no un JSON.
        if (!$request->expectsJson()) {
            abort(403, 'No tienes permisos para acceder a este recurso');
        }

        return response()->json([
            'message' => 'No tienes permisos para acceder a este recurso'
        ], 403);
    }
}
