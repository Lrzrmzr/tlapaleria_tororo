<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

/**
 * Auditoría run-1: el seeder creaba un root con contraseña fija y documentada
 * en cualquier entorno y la reseteaba en cada db:seed.
 */
class AdminUserSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'root']);
    }

    /** Se invoca directo: artisan db:seed en producción pide confirmación interactiva. */
    private function runSeeder(): void
    {
        $this->app->make(AdminUserSeeder::class)->run();
    }

    private function asEnvironment(string $env): void
    {
        $this->app['env'] = $env;
    }

    public function test_en_produccion_sin_password_configurada_no_crea_el_root(): void
    {
        $this->asEnvironment('production');
        config(['app.admin_seed_password' => null]);

        try {
            $this->runSeeder();
            $this->fail('El seeder debió fallar sin ADMIN_SEED_PASSWORD en producción.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ADMIN_SEED_PASSWORD', $e->getMessage());
        }

        $this->assertDatabaseMissing('users', ['email' => AdminUserSeeder::EMAIL]);
    }

    public function test_en_produccion_rechaza_password_corta(): void
    {
        $this->asEnvironment('production');
        config(['app.admin_seed_password' => 'corta']);

        $this->expectException(RuntimeException::class);
        $this->runSeeder();
    }

    public function test_en_produccion_usa_la_password_configurada_y_no_la_de_desarrollo(): void
    {
        $this->asEnvironment('production');
        config(['app.admin_seed_password' => 'una-password-larga-y-segura']);

        $this->runSeeder();

        $user = User::where('email', AdminUserSeeder::EMAIL)->firstOrFail();
        $this->assertTrue($user->isRoot());
        $this->assertTrue(Hash::check('una-password-larga-y-segura', $user->password));
        $this->assertFalse(Hash::check(AdminUserSeeder::DEV_PASSWORD, $user->password));
    }

    public function test_en_produccion_no_sobrescribe_la_password_de_un_root_existente(): void
    {
        $this->asEnvironment('production');
        config(['app.admin_seed_password' => 'una-password-larga-y-segura']);
        $this->runSeeder();

        User::where('email', AdminUserSeeder::EMAIL)->first()->forceFill(['password' => 'cambiada-por-el-dueno'])->save();

        config(['app.admin_seed_password' => 'otra-password-larga-distinta']);
        $this->runSeeder();

        $user = User::where('email', AdminUserSeeder::EMAIL)->first();
        $this->assertTrue(Hash::check('cambiada-por-el-dueno', $user->password));
    }

    public function test_en_local_sin_configuracion_usa_la_password_de_desarrollo(): void
    {
        $this->asEnvironment('local');
        config(['app.admin_seed_password' => null]);

        $this->runSeeder();

        $user = User::where('email', AdminUserSeeder::EMAIL)->firstOrFail();
        $this->assertTrue(Hash::check(AdminUserSeeder::DEV_PASSWORD, $user->password));
        $this->assertTrue($user->isRoot());
    }
}
