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
}
