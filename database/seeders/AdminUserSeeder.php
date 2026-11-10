<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Crea el usuario root inicial.
 *
 * Auditoría run-1: antes creaba admin@admin.com con una contraseña fija y
 * documentada en cualquier entorno, y la reseteaba en cada db:seed. Ahora:
 *  - fuera de local/testing exige ADMIN_SEED_PASSWORD (mín. 12 caracteres);
 *  - si el usuario ya existe, nunca sobrescribe su contraseña fuera de local;
 *  - solo imprime la contraseña cuando es la de desarrollo.
 */
class AdminUserSeeder extends Seeder
{
    public const EMAIL = 'admin@admin.com';

    /** Contraseña de desarrollo que usan los tests e2e (tests/e2e). Solo local/testing. */
    public const DEV_PASSWORD = 'pasword123';

    public function run(): void
    {
        $isDevEnvironment = app()->environment('local', 'testing');
        $password = config('app.admin_seed_password');

        if (empty($password)) {
            if (!$isDevEnvironment) {
                throw new RuntimeException(
                    'Define ADMIN_SEED_PASSWORD (mínimo 12 caracteres) para crear el usuario root fuera de local/testing.'
                );
            }
            $password = self::DEV_PASSWORD;
        } elseif (!$isDevEnvironment && mb_strlen($password) < 12) {
            throw new RuntimeException('ADMIN_SEED_PASSWORD debe tener al menos 12 caracteres.');
        }

        $user = User::where('email', self::EMAIL)->first();

        if (!$user) {
            $user = User::create([
                'name'     => 'admin',
                'email'    => self::EMAIL,
                'password' => $password,
                'active'   => true,
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();
        } elseif ($isDevEnvironment) {
            // En desarrollo se restablece para que los tests e2e sean reproducibles.
            $user->forceFill(['password' => $password, 'active' => true])->save();
        }

        $rootRoleId = Role::where('name', 'root')->value('id');

        if ($rootRoleId && !DB::table('role_user')->where('user_id', $user->id)->where('role_id', $rootRoleId)->exists()) {
            DB::table('role_user')->insert([
                'user_id'    => $user->id,
                'role_id'    => $rootRoleId,
                'created_at' => now(),
                'updated_at' => null,
            ]);
        }

        $shownPassword = $password === self::DEV_PASSWORD ? self::DEV_PASSWORD : '(definida en ADMIN_SEED_PASSWORD)';
        $this->command?->info('✅ Usuario root: ' . self::EMAIL . ' / ' . $shownPassword);
    }
}
