<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::updateOrCreate(
            ['email' => 'admin@admin.com'],
            [
                'name'              => 'admin',
                'password'          => 'pasword123',
                'active'            => true,
                'email_verified_at' => now(),
            ]
        );

        // Asignar rol root si no lo tiene ya
        if (!DB::table('role_user')->where('user_id', $user->id)->where('role_id', 1)->exists()) {
            DB::table('role_user')->insert([
                'user_id'    => $user->id,
                'role_id'    => 1,
                'created_at' => now(),
                'updated_at' => null,
            ]);
        }

        $this->command->info('✅ Usuario admin creado: admin@admin.com / pasword123');
    }
}
