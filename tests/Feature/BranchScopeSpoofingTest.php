<?php

namespace Tests\Feature;

use App\Models\BranchInventory;
use App\Models\CashRegister;
use App\Models\Merma;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Support\CreatesBusinessFixtures;
use Tests\TestCase;

/**
 * Auditoría run-1: CheckBranchAccess inyectaba is_root/user_branch_id con
 * $request->merge() y los controladores lo leían con $request->is_root.
 * Ese getter resuelve contra all(), donde los archivos subidos pisan el input,
 * así que un multipart con un archivo llamado "is_root" volvía "root" a
 * cualquier usuario y le permitía escribir en otra sucursal.
 */
class BranchScopeSpoofingTest extends TestCase
{
    use RefreshDatabase, CreatesBusinessFixtures;

    public function test_un_archivo_llamado_is_root_no_permite_registrar_merma_en_otra_sucursal(): void
    {
        $miSucursal   = $this->makeBranch(['code' => 'A']);
        $otraSucursal = $this->makeBranch(['code' => 'B']);
        $trabajador   = $this->makeUser($miSucursal, 'trabajador');
        $producto     = $this->makeProduct();
        $invOtra      = $this->makeInventory($producto, $otraSucursal, 10);

        $this->actingAs($trabajador)->post(route('mermas.store'), [
            'branch_id'  => $otraSucursal->id,
            'product_id' => $producto->id,
            'quantity'   => 3,
            'motivo'     => 'otro',
            'fecha'      => now()->toDateString(),
            'is_root'    => UploadedFile::fake()->create('x.txt', 1),
        ]);

        $this->assertSame(10, (int) BranchInventory::find($invOtra->id)->stock);
        $this->assertFalse(Merma::where('branch_id', $otraSucursal->id)->exists());
    }

    public function test_un_archivo_llamado_is_root_no_permite_abrir_caja_en_otra_sucursal(): void
    {
        $miSucursal   = $this->makeBranch(['code' => 'A']);
        $otraSucursal = $this->makeBranch(['code' => 'B']);
        $trabajador   = $this->makeUser($miSucursal, 'trabajador');

        $this->actingAs($trabajador)->post(route('caja.abrir'), [
            'fondo_inicial' => 100,
            'branch_id'     => $otraSucursal->id,
            'is_root'       => UploadedFile::fake()->create('x.txt', 1),
        ]);

        $this->assertFalse(CashRegister::where('branch_id', $otraSucursal->id)->exists());
    }

    public function test_un_campo_de_formulario_is_root_tampoco_altera_el_alcance(): void
    {
        $miSucursal   = $this->makeBranch(['code' => 'A']);
        $otraSucursal = $this->makeBranch(['code' => 'B']);
        $trabajador   = $this->makeUser($miSucursal, 'trabajador');

        $this->actingAs($trabajador)->post(route('caja.abrir'), [
            'fondo_inicial'  => 100,
            'branch_id'      => $otraSucursal->id,
            'is_root'        => '1',
            'user_branch_id' => $otraSucursal->id,
        ]);

        $this->assertFalse(CashRegister::where('branch_id', $otraSucursal->id)->exists());
        $this->assertTrue(CashRegister::where('branch_id', $miSucursal->id)->exists());
    }
}
