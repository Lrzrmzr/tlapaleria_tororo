<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Faltante;
use Illuminate\Http\Request;

class FaltanteApiController extends Controller
{
    /**
     * Listar faltantes
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $query = Faltante::with(['user', 'branch'])->latest();

        // Si no es root, solo ve faltantes de su sucursal
        if (!$user->isRoot() && $user->branch_id) {
            $query->where('branch_id', $user->branch_id);
        } elseif ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        // Filtro por confirmado
        if ($request->filled('confirmado')) {
            $query->where('confirmado', $request->boolean('confirmado'));
        }

        // Filtro por búsqueda en descripción o pedido
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('descripcion', 'like', "%{$search}%")
                  ->orWhere('pedido', 'like', "%{$search}%");
            });
        }

        // Filtro por fecha
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $perPage = min(max((int) $request->get('per_page', 50), 1), 200);
        $faltantes = $query->paginate($perPage);

        // Contar pendientes (no confirmados)
        $pendientes = Faltante::where('confirmado', false);
        if (!$user->isRoot() && $user->branch_id) {
            $pendientes->where('branch_id', $user->branch_id);
        }
        $totalPendientes = $pendientes->count();

        return response()->json([
            'success' => true,
            'data' => $faltantes->items(),
            'pagination' => [
                'total' => $faltantes->total(),
                'per_page' => $faltantes->perPage(),
                'current_page' => $faltantes->currentPage(),
                'last_page' => $faltantes->lastPage(),
            ],
            'stats' => [
                'total_pendientes' => $totalPendientes,
            ]
        ]);
    }

    /**
     * Obtener un faltante específico
     */
    public function show(int $id)
    {
        $faltante = Faltante::with(['user', 'branch'])->findOrFail($id);
        $this->authorize('update', $faltante);

        return response()->json(['success' => true, 'data' => $faltante]);
    }

    /**
     * Crear un nuevo faltante
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'descripcion' => 'required|string|max:255',
            'pedido'      => 'required|in:GENERAL,TRUPER',
            'branch_id'   => 'required|exists:branches,id',
        ]);

        // No root: forzar la sucursal del usuario
        $user = $request->user();
        if (!$user->isRoot() && $user->branch_id) {
            $validated['branch_id'] = $user->branch_id;
        }

        $validated['user_id']   = $user->id;
        $validated['confirmado'] = false;

        $faltante = Faltante::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Faltante registrado exitosamente',
            'data'    => $faltante->load(['user', 'branch']),
        ], 201);
    }

    /**
     * Actualizar un faltante
     */
    public function update(Request $request, int $id)
    {
        $faltante = Faltante::findOrFail($id);
        $this->authorize('update', $faltante);

        $validated = $request->validate([
            'descripcion' => 'sometimes|string|max:255',
            'pedido'      => 'sometimes|in:GENERAL,TRUPER',
            'confirmado'  => 'sometimes|boolean',
        ]);

        $faltante->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Faltante actualizado exitosamente',
            'data'    => $faltante->load(['user', 'branch']),
        ]);
    }

    /**
     * Marcar faltante como completado (confirmado)
     */
    public function markAsCompleted(int $id)
    {
        $faltante = Faltante::findOrFail($id);
        $this->authorize('update', $faltante);

        $faltante->update(['confirmado' => true]);

        return response()->json([
            'success' => true,
            'message' => 'Faltante marcado como completado',
            'data'    => $faltante->load(['user', 'branch']),
        ]);
    }

    /**
     * Eliminar un faltante
     */
    public function destroy(int $id, Request $request)
    {
        $user     = $request->user();
        $userRole = $user->roles->first();

        if (!$userRole || !in_array($userRole->name, ['administrador', 'root'])) {
            return response()->json(['success' => false, 'message' => 'No tienes permisos para eliminar faltantes.'], 403);
        }

        $faltante = Faltante::findOrFail($id);
        $this->authorize('delete', $faltante);

        $faltante->delete();

        return response()->json(['success' => true, 'message' => 'Faltante eliminado exitosamente']);
    }
}
