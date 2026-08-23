<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Gasto;
use App\Services\GastoService;
use Illuminate\Http\Request;
use RuntimeException;

class GastoApiController extends Controller
{
    public function __construct(private GastoService $gastoService)
    {
    }

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
                'total' => $gastos->sum('monto'),
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

        // No root: siempre la sucursal del propio usuario (nunca la del body —
        // antes un usuario no-root sin sucursal asignada podía elegir cualquiera).
        // GastoService::registrar rechaza branch_id nulo (root sin selección).
        $branchId = $user->isRoot() ? ($validated['branch_id'] ?? null) : $user->branch_id;

        try {
            $gasto = $this->gastoService->registrar($validated, $branchId, $user->id);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Gasto registrado exitosamente',
            'data'    => $gasto->load('user'),
        ], 201);
    }

    /**
     * Obtener un gasto específico
     */
    public function show(int $id)
    {
        $gasto = Gasto::with('user')->findOrFail($id);
        $this->authorize('update', $gasto);

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
        $this->authorize('update', $gasto);

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
    public function destroy(int $id)
    {
        $gasto = Gasto::findOrFail($id);
        $this->authorize('delete', $gasto);

        $gasto->delete();

        return response()->json([
            'success' => true,
            'message' => 'Gasto eliminado exitosamente'
        ]);
    }
}
