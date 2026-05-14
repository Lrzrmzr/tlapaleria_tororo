<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Gasto;
use Illuminate\Http\Request;

class GastoApiController extends Controller
{
    /**
     * Listar gastos
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $query = Gasto::with('user')->latest();

        // Filtrar por sucursal del usuario si no es root
        if (!$user->isRoot() && $user->branch_id) {
            $query->where('branch_id', $user->branch_id);
        } elseif ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        // Filtro por fecha
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $perPage = min((int) $request->get('per_page', 50), 200);
        $gastos = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $gastos->items(),
            'pagination' => [
                'total' => $gastos->total(),
                'per_page' => $gastos->perPage(),
                'current_page' => $gastos->currentPage(),
                'last_page' => $gastos->lastPage(),
            ],
            'stats' => [
                'total' => $gastos->sum('total'),
            ]
        ]);
    }

    /**
     * Crear un nuevo gasto
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'descripcion' => 'required|string|max:255',
            'monto'       => 'required|numeric|min:0.01', // columna renombrada de 'total' a 'monto'
            'branch_id'   => 'nullable|exists:branches,id',
        ]);

        // No root: usar la sucursal del usuario
        if (!$user->isRoot() && $user->branch_id) {
            $validated['branch_id'] = $user->branch_id;
        }

        $validated['user_id'] = $user->id;

        $gasto = Gasto::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Gasto registrado exitosamente',
            'data'    => $gasto->load('user'),
        ], 201);
    }

    /**
     * Obtener un gasto específico
     */
    public function show(int $id, Request $request)
    {
        $gasto = Gasto::with('user')->findOrFail($id);
        $this->authorizeBranch($gasto->branch_id, $request->user());

        return response()->json([
            'success' => true,
            'data' => $gasto
        ]);
    }

    /**
     * Actualizar un gasto
     */
    public function update(Request $request, int $id)
    {
        $gasto = Gasto::findOrFail($id);
        $this->authorizeBranch($gasto->branch_id, $request->user());

        $validated = $request->validate([
            'descripcion' => 'sometimes|required|string|max:255',
            'monto'       => 'sometimes|required|numeric|min:0.01',
        ]);

        $gasto->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Gasto actualizado exitosamente',
            'data' => $gasto->load('user')
        ]);
    }

    /**
     * Eliminar un gasto
     */
    public function destroy(Request $request, int $id)
    {
        $user = $request->user();
        $userRole = $user->roles->first();

        if (!$userRole || ($userRole->name !== 'administrador' && $userRole->name !== 'root')) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes permisos para eliminar gastos'
            ], 403);
        }

        $gasto = Gasto::findOrFail($id);
        $this->authorizeBranch($gasto->branch_id, $user);
        $gasto->delete();

        return response()->json([
            'success' => true,
            'message' => 'Gasto eliminado exitosamente'
        ]);
    }

    private function authorizeBranch(?int $branchId, \App\Models\User $user): void
    {
        if ($user->isRoot()) return;
        if ($branchId !== $user->branch_id) {
            abort(response()->json(['success' => false, 'message' => 'No tienes acceso a datos de otra sucursal.'], 403));
        }
    }
}
