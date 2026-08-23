<?php
namespace App\Http\Controllers;

use App\Http\Requests\StoreGastoRequest;
use App\Models\Branch;
use App\Models\Gasto;
use App\Services\GastoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use RuntimeException;

class GastoController extends Controller
{
    public function __construct(private GastoService $gastoService)
    {
    }

    public function index(Request $request)
    {
        $isRoot = $request->is_root ?? false;
        $query = Gasto::with('user');

        if (!$isRoot) {
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
            'isRoot' => $isRoot,
            // Root no tiene sucursal propia: debe elegir una para registrar
            // gastos (GastoService los exige con sucursal resuelta, siempre en efectivo).
            'branches' => $isRoot ? Branch::where('active', true)->orderBy('name')->get(['id', 'name']) : collect(),
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
        $isRoot = $request->is_root ?? false;

        if ($isRoot) {
            $request->validate(['branch_id' => 'nullable|exists:branches,id']);
        }

        $branchId = $isRoot ? $request->branch_id : $request->user_branch_id;

        try {
            $this->gastoService->registrar(
                $request->validated() + ['fecha_gasto' => $request->fecha_gasto],
                $branchId,
                Auth::id()
            );
        } catch (RuntimeException $e) {
            return redirect()->back()->withErrors(['error' => $e->getMessage()]);
        }

        return redirect()->back();
    }

    public function update(Request $request, Gasto $gasto)
    {
        $this->authorize('update', $gasto);

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
        $this->authorize('delete', $gasto);

        $gasto->delete();
        return redirect()->back();
    }
}
