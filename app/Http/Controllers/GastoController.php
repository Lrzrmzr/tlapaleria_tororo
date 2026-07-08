<?php
namespace App\Http\Controllers;

use App\Http\Requests\StoreGastoRequest;
use App\Models\Gasto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class GastoController extends Controller
{
    public function index(Request $request)
    {
        $query = Gasto::with('user');

        if (!($request->is_root ?? false)) {
            $query->where('branch_id', $request->user_branch_id);
        }

        $gastos = $query->latest()
            ->get()
            ->map(fn($gasto) => [
                'id'          => $gasto->id,
                'descripcion' => $gasto->descripcion,
                'monto'       => $gasto->monto,
                'created_at'  => $gasto->created_at->toISOString(),
                'fecha_gasto' => optional($gasto->fecha_gasto)->toDateString(),
                'user'        => $gasto->user ? [
                    'id'   => $gasto->user->id,
                    'name' => $gasto->user->name,
                ] : null,
            ])->values();

        $user = Auth::user();

        return Inertia::render('Gastos/Index', [
            'gastos' => $gastos,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->roles->first()?->name ?? null,
            ],
        ]);
    }

    public function store(StoreGastoRequest $request)
    {
        Gasto::create([
            'descripcion' => $request->descripcion,
            'monto'       => $request->monto,
            'user_id'     => Auth::id(),
            'branch_id'   => $request->user_branch_id,
            'fecha_gasto' => $request->fecha_gasto ?? now()->toDateString(),
        ]);
        return redirect()->back();
    }

    public function update(Request $request, Gasto $gasto)
    {
        $this->authorizeBranch($gasto->branch_id, $request);

        $request->validate([
            'descripcion' => 'required|string|max:255',
            'monto'       => 'required|numeric|min:0.01',
        ]);

        $gasto->update([
            'descripcion' => $request->descripcion,
            'monto'       => $request->monto,
        ]);

        return redirect()->back();
    }

    public function destroy(Gasto $gasto)
    {
        $user     = Auth::user();
        $userRole = $user->roles->first();

        if (!$userRole || !in_array($userRole->name, ['administrador', 'root'])) {
            abort(403, 'Solo el administrador puede eliminar gastos.');
        }

        $this->authorizeBranch($gasto->branch_id, request());

        $gasto->delete();
        return redirect()->back();
    }

    private function authorizeBranch(?int $resourceBranchId, \Illuminate\Http\Request $request): void
    {
        $isRoot = $request->is_root ?? false;
        if (!$isRoot && $resourceBranchId !== $request->user_branch_id) {
            abort(403, 'No tienes acceso a datos de otra sucursal.');
        }
    }
}
