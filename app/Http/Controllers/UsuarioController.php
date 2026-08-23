<?php
namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Role;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class UsuarioController extends Controller
{
    public function index()
    {
        $currentUser = User::with('roles')->find(Auth::id());
        $this->authorize('viewAny', User::class);
        $isRoot = $currentUser->isRoot();

        $usuarios = User::with(['roles', 'branch'])
            ->when(!$isRoot, fn ($q) => $q->where('branch_id', $currentUser->branch_id))
            ->get()->map(function ($usuario) {
            $primerRol = $usuario->roles->first();
            return [
                'id' => $usuario->id,
                'name' => $usuario->name,
                'email' => $usuario->email,
                'role' => $primerRol ? [
                    'id' => $primerRol->id,
                    'name' => $primerRol->name,
                ] : null,
                'branch' => $usuario->branch ? [
                    'id' => $usuario->branch->id,
                    'name' => $usuario->branch->name,
                    'code' => $usuario->branch->code,
                ] : null,
                'active' => $usuario->active,
                'created_at' => $usuario->created_at->format('d/m/Y'),
                'email_verified' => $usuario->email_verified_at !== null,
            ];
        });

        $roles = Role::all();
        $branches = Branch::where('active', true)->orderBy('name')->get();
        $user = $currentUser;
        $userRole = $user->roles->first();

        // Estadísticas — acotadas a la sucursal para un administrador no-root
        $statsScope = fn () => User::when(!$isRoot, fn ($q) => $q->where('branch_id', $currentUser->branch_id));
        $totalUsuarios = $statsScope()->count();
        $usuariosConRol = $statsScope()->has('roles')->count();
        $usuariosSinRol = $statsScope()->doesntHave('roles')->count();
        $usuariosVerificados = $statsScope()->whereNotNull('email_verified_at')->count();

        return Inertia::render('Usuarios/Index', [
            'usuarios' => $usuarios,
            'roles' => $roles,
            'branches' => $branches,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $userRole ? [
                    'id' => $userRole->id,
                    'name' => $userRole->name,
                ] : null,
            ],
            'stats' => [
                'total' => $totalUsuarios,
                'conRol' => $usuariosConRol,
                'sinRol' => $usuariosSinRol,
                'verificados' => $usuariosVerificados,
            ]
        ]);
    }

    public function store(Request $request)
    {
        $currentUser = User::with('roles')->find(Auth::id());
        $this->authorize('create', User::class);

        $validated = $request->validate([
            'name'                  => 'required|string|max:255',
            'email'                 => 'required|email|max:255|unique:users,email',
            'password'              => 'required|string|min:8|confirmed',
            'rol_id'                => 'nullable|exists:roles,id',
            'branch_id'             => 'nullable|exists:branches,id',
        ]);

        $rolRoot = Role::where('name', 'root')->first();
        if (!empty($validated['rol_id']) && $rolRoot && (int) $validated['rol_id'] === $rolRoot->id && !$currentUser->isRoot()) {
            abort(403, 'No puedes asignar el rol root.');
        }

        // Branch admins can only create users for their own branch
        if ($currentUser->isBranchAdmin() && !$currentUser->isRoot()) {
            $validated['branch_id'] = $currentUser->branch_id;
        }

        $user = User::create([
            'name'      => $validated['name'],
            'email'     => $validated['email'],
            'password'  => bcrypt($validated['password']),
            'branch_id' => $validated['branch_id'] ?? null,
            'active'    => true,
        ]);

        $user->email_verified_at = now();
        $user->save();

        if (!empty($validated['rol_id'])) {
            $user->roles()->attach((int) $validated['rol_id']);
        }

        return redirect()->back()->with('success', 'Usuario creado exitosamente.');
    }

    public function asignarRol(Request $request)
    {
        $currentUser = User::with('roles')->find(Auth::id());

        $request->validate([
            'usuario_id' => 'required|exists:users,id',
            'rol_id'     => 'nullable|exists:roles,id',
        ]);

        $usuario = User::findOrFail($request->usuario_id);

        // Un administrador de sucursal solo puede asignar roles a usuarios de
        // su propia sucursal, y nunca a un usuario root (evita que un admin
        // comprometido en una sucursal escale privilegios en otra sucursal).
        $this->authorize('assignRole', [User::class, $usuario]);

        $rolRoot = Role::where('name', 'root')->first();

        // Solo root puede asignar el rol root
        if ($request->rol_id && $rolRoot && $request->rol_id == $rolRoot->id && !$currentUser->isRoot()) {
            abort(403, 'No puedes asignar el rol root.');
        }

        $usuario->roles()->detach();

        if ($request->rol_id) {
            $usuario->roles()->attach($request->rol_id);
        }

        return redirect()->back()->with('success', 'Rol asignado correctamente.');
    }

    public function asignarSucursal(Request $request, User $user)
    {
        $currentUser = User::with('roles')->find(Auth::id());

        // Misma regla de alcance que asignarRol: un admin de sucursal solo
        // puede reasignar usuarios sin sucursal o ya asignados a la suya.
        $this->authorize('assignBranch', [User::class, $user]);

        $request->validate([
            'branch_id' => 'nullable|exists:branches,id'
        ]);

        $branchId = $request->branch_id;

        // Si se está asignando una sucursal
        if ($branchId) {
            $branch = Branch::findOrFail($branchId);

            // Verificar si el usuario que se está asignando es administrador
            $userRoles = $user->roles;
            $isAdminRole = $userRoles->contains('name', 'administrador');

            if ($isAdminRole) {
                // Verificar que la sucursal no tenga ya un administrador
                $adminRoleId = Role::where('name', 'administrador')->value('id');
                $existingAdmin = User::where('users.branch_id', $branchId)
                    ->where('users.id', '!=', $user->id)
                    ->join('role_user', 'users.id', '=', 'role_user.user_id')
                    ->where('role_user.role_id', $adminRoleId)
                    ->select('users.*')
                    ->first();

                if ($existingAdmin) {
                    return redirect()->back()->withErrors([
                        'error' => "La sucursal '{$branch->name}' ya tiene un administrador asignado ({$existingAdmin->name}). Solo puede haber un administrador por sucursal."
                    ]);
                }
            }

            // Administradores de sucursal solo pueden asignar a su propia sucursal
            if ($currentUser->isBranchAdmin() && !$currentUser->isRoot()) {
                if ($currentUser->branch_id != $branchId) {
                    abort(403, 'Solo puedes asignar usuarios a tu propia sucursal');
                }
            }
        }

        // Asignar o remover sucursal
        $user->branch_id = $branchId;
        $user->save();

        return redirect()->back()->with('success', $branchId
            ? 'Sucursal asignada correctamente'
            : 'Sucursal removida correctamente'
        );
    }
}
