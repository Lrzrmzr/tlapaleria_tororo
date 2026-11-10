<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckBranchAccess
{
    /**
     * Handle an incoming request.
     *
     * Verifica que el usuario tenga acceso a los datos de la sucursal
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Si no hay usuario autenticado, continuar (el middleware auth se encargará)
        if (!$user) {
            return $next($request);
        }

        // Usuarios desactivados: cerrar sesión inmediatamente
        if (!$user->active) {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            return redirect()->route('login')
                ->withErrors(['error' => 'Tu cuenta ha sido desactivada. Contacta al administrador.']);
        }

        // Clientes NO tienen acceso al sistema actualmente (para futuras ventas en línea)
        if ($user->isClient()) {
            return redirect()->route('login')
                ->withErrors(['error' => 'Los clientes no tienen acceso al sistema de administración. Este rol está reservado para futuras ventas en línea.']);
        }

        // Root tiene acceso a todo sin necesidad de sucursal
        if ($user->isRoot()) {
            $this->setBranchScope($request, null, true); // null = ve todas las sucursales
            return $next($request);
        }

        // Administradores y Trabajadores NECESITAN sucursal asignada
        if (!$user->hasSystemAccess()) {
            // Redirigir a página especial de "Sin Sucursal Asignada"
            return redirect()->route('sin-sucursal')
                ->withErrors(['error' => 'No tienes una sucursal asignada. Contacta al administrador para que te asigne una sucursal.']);
        }

        // Agregar la sucursal del usuario al request para uso posterior
        $this->setBranchScope($request, $user->branch_id !== null ? (int) $user->branch_id : null, false);

        return $next($request);
    }

    /**
     * El alcance de sucursal se guarda en $request->attributes (bolsa interna del
     * servidor), NO en el input. Antes se usaba $request->merge() y los
     * controladores lo leían con $request->is_root; ese getter mágico resuelve
     * contra all(), donde los archivos subidos pisan al input, así que un
     * multipart con un archivo llamado "is_root" se convertía en root.
     * Los controladores deben leerlo con $request->attributes->get(...).
     */
    private function setBranchScope(Request $request, ?int $branchId, bool $isRoot): void
    {
        $request->attributes->set('user_branch_id', $branchId);
        $request->attributes->set('is_root', $isRoot);
    }
}
