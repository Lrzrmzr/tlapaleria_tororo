<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesBusinessFixtures;
use Tests\TestCase;

/**
 * Cubre el hallazgo alto de la auditoría: un administrador de sucursal podía
 * asignar/quitar roles a usuarios de OTRA sucursal (escalación de privilegios).
 */
class UsuarioControllerAuthorizationTest extends TestCase
{
    use RefreshDatabase, CreatesBusinessFixtures;

    public function test_un_admin_de_sucursal_no_puede_asignar_rol_a_usuario_de_otra_sucursal(): void
    {
        $miSucursal = $this->makeBranch(['code' => 'MIA']);
        $otraSucursal = $this->makeBranch(['code' => 'OTRA']);

        $admin = $this->makeUser($miSucursal, 'administrador');
        $victima = $this->makeUser($otraSucursal, 'trabajador');

        $response = $this->actingAs($admin)->post('/usuarios/asignar-rol', [
            'usuario_id' => $victima->id,
            'rol_id'     => \App\Models\Role::where('name', 'administrador')->first()->id,
        ]);

        $response->assertForbidden();
        $this->assertTrue($victima->fresh()->isWorker());
    }

    public function test_un_admin_de_sucursal_si_puede_asignar_rol_a_usuario_de_su_propia_sucursal(): void
    {
        $miSucursal = $this->makeBranch(['code' => 'MIA']);
        $admin = $this->makeUser($miSucursal, 'administrador');
        $companero = $this->makeUser($miSucursal, 'trabajador');

        $rolAdmin = \App\Models\Role::where('name', 'administrador')->first();

        $response = $this->actingAs($admin)->post('/usuarios/asignar-rol', [
            'usuario_id' => $companero->id,
            'rol_id'     => $rolAdmin->id,
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertTrue($companero->fresh()->isBranchAdmin());
    }

    /**
     * Auditoría run-1: rol_id enviado como arreglo pasaba la regla `exists`,
     * evadía la comparación laxa `==` contra el id de root y attach() insertaba
     * todos los ids. Un admin de sucursal podía hacerse root a sí mismo.
     */
    public function test_un_admin_de_sucursal_no_puede_escalar_a_root_enviando_rol_id_como_arreglo(): void
    {
        $miSucursal = $this->makeBranch(['code' => 'MIA']);
        $admin = $this->makeUser($miSucursal, 'administrador');
        $rolRoot = \App\Models\Role::firstOrCreate(['name' => 'root']);

        $response = $this->actingAs($admin)->post('/usuarios/asignar-rol', [
            'usuario_id' => $admin->id,
            'rol_id'     => [$rolRoot->id],
        ]);

        $response->assertSessionHasErrors('rol_id');
        $this->assertFalse($admin->fresh()->isRoot());
        $this->assertTrue($admin->fresh()->isBranchAdmin());
    }

    public function test_un_admin_de_sucursal_no_puede_asignar_root_como_escalar(): void
    {
        $miSucursal = $this->makeBranch(['code' => 'MIA']);
        $admin = $this->makeUser($miSucursal, 'administrador');
        $companero = $this->makeUser($miSucursal, 'trabajador');
        $rolRoot = \App\Models\Role::firstOrCreate(['name' => 'root']);

        $this->actingAs($admin)->post('/usuarios/asignar-rol', [
            'usuario_id' => $companero->id,
            'rol_id'     => (string) $rolRoot->id,
        ])->assertForbidden();

        $this->assertFalse($companero->fresh()->isRoot());
    }

    public function test_crear_usuario_rechaza_rol_id_como_arreglo(): void
    {
        $miSucursal = $this->makeBranch(['code' => 'MIA']);
        $admin = $this->makeUser($miSucursal, 'administrador');
        $rolTrabajador = \App\Models\Role::firstOrCreate(['name' => 'trabajador']);

        $this->actingAs($admin)->post('/usuarios/crear', [
            'name'                  => 'Nuevo',
            'email'                 => 'nuevo@example.test',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
            'rol_id'                => [$rolTrabajador->id],
        ])->assertSessionHasErrors('rol_id');

        $this->assertDatabaseMissing('users', ['email' => 'nuevo@example.test']);
    }
}
