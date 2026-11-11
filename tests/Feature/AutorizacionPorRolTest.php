<?php

namespace Tests\Feature;

use App\Models\CuentaPorCobrar;
use App\Models\Customer;
use App\Models\Gasto;
use App\Services\VentaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesBusinessFixtures;
use Tests\TestCase;

/**
 * Regla del negocio: root y administrador de sucursal (encargado) ven
 * reportes, pedidos, dashboard, faltantes y utilidades, y son los únicos que
 * pueden modificar montos. El trabajador (vendedor) no.
 */
class AutorizacionPorRolTest extends TestCase
{
    use RefreshDatabase, CreatesBusinessFixtures;

    public static function rutasDeEncargado(): array
    {
        return [
            'reportes'  => ['/reportes'],
            'tendencia' => ['/reportes/tendencia'],
            'csv'       => ['/reportes/csv'],
            'pedidos'   => ['/pedidos'],
            'faltantes' => ['/faltantes'],
        ];
    }

    #[DataProvider('rutasDeEncargado')]
    public function test_el_trabajador_no_entra_a_pantallas_de_encargado(string $url): void
    {
        $trabajador = $this->makeUser($this->makeBranch(), 'trabajador');

        $this->actingAs($trabajador)->get($url)->assertForbidden();
    }

    #[DataProvider('rutasDeEncargado')]
    public function test_el_administrador_si_entra_a_pantallas_de_encargado(string $url): void
    {
        $admin = $this->makeUser($this->makeBranch(), 'administrador');

        $this->actingAs($admin)->get($url)->assertOk();
    }

    public function test_el_trabajador_no_puede_usar_el_asistente_ni_parsear_documentos(): void
    {
        $trabajador = $this->makeUser($this->makeBranch(), 'trabajador');

        $this->actingAs($trabajador)->postJson('/asistente', ['pregunta' => 'ventas de hoy'])->assertForbidden();
        $this->actingAs($trabajador)->postJson('/pedidos/parse', [])->assertForbidden();
    }

    public function test_el_trabajador_que_entra_al_dashboard_va_a_ventas(): void
    {
        $trabajador = $this->makeUser($this->makeBranch(), 'trabajador');

        $this->actingAs($trabajador)->get('/dashboard')->assertRedirect(route('ventas.index'));
    }

    public function test_el_administrador_ve_el_dashboard(): void
    {
        $admin = $this->makeUser($this->makeBranch(), 'administrador');

        $this->actingAs($admin)->get('/dashboard')->assertOk();
    }

    public function test_el_trabajador_no_puede_editar_un_gasto(): void
    {
        $branch = $this->makeBranch();
        $trabajador = $this->makeUser($branch, 'trabajador');
        $gasto = Gasto::create([
            'branch_id' => $branch->id, 'user_id' => $trabajador->id,
            'descripcion' => 'Luz', 'monto' => 100, 'fecha_gasto' => now()->toDateString(),
        ]);

        $this->actingAs($trabajador)
            ->put("/gastos/{$gasto->id}", ['descripcion' => 'Luz', 'monto' => 1])
            ->assertForbidden();

        $this->assertEquals(100, (float) $gasto->fresh()->monto);
    }

    public function test_el_trabajador_no_puede_editar_el_monto_de_una_cuenta_por_cobrar(): void
    {
        $branch = $this->makeBranch();
        $trabajador = $this->makeUser($branch, 'trabajador');
        $customer = Customer::create(['name' => 'Cliente', 'email' => 'c' . uniqid() . '@example.com']);
        $cuenta = CuentaPorCobrar::create([
            'customer_id' => $customer->id, 'branch_id' => $branch->id, 'user_id' => $trabajador->id,
            'concepto' => 'Fiado', 'monto_total' => 500, 'monto_cobrado' => 0, 'saldo' => 500,
            'fecha' => now()->toDateString(), 'status' => 'pendiente',
        ]);

        $this->actingAs($trabajador)->put("/cuentas-por-cobrar/{$cuenta->id}", [
            'customer_id' => $customer->id, 'concepto' => 'Fiado', 'monto_total' => 1,
            'fecha' => now()->toDateString(),
        ])->assertForbidden();

        $this->assertEquals(500, (float) $cuenta->fresh()->monto_total);
    }

    public function test_el_trabajador_puede_ver_un_gasto_por_api_pero_no_editarlo(): void
    {
        $branch = $this->makeBranch();
        $trabajador = $this->makeUser($branch, 'trabajador');
        $gasto = Gasto::create([
            'branch_id' => $branch->id, 'user_id' => $trabajador->id,
            'descripcion' => 'Agua', 'monto' => 50, 'fecha_gasto' => now()->toDateString(),
        ]);

        Passport::actingAs($trabajador);

        $this->getJson("/api/mobile/gastos/{$gasto->id}")->assertOk();
        $this->putJson("/api/mobile/gastos/{$gasto->id}", ['descripcion' => 'Agua', 'monto' => 1])->assertForbidden();
        $this->getJson('/api/mobile/faltantes')->assertForbidden();
    }

    public function test_la_utilidad_de_las_ventas_solo_la_ve_el_encargado(): void
    {
        $branch = $this->makeBranch();
        $trabajador = $this->makeUser($branch, 'trabajador');
        $admin = $this->makeUser($branch, 'administrador');
        $product = $this->makeProduct(['price' => 100]);
        $this->makeInventory($product, $branch, 10, ['cost' => 60]);

        app(VentaService::class)->registrarCatalogo(
            [['product_id' => $product->id, 'quantity' => 1]], 'tarjeta', null, null, $branch->id, $trabajador->id
        );

        // Rango amplio: en sqlite el cast 'date' guarda hora y el filtro del mismo día lo excluye.
        $url = '/ventas?fecha_desde=' . now()->subDay()->toDateString() . '&fecha_hasta=' . now()->addDay()->toDateString();

        $this->actingAs($trabajador)->get($url)->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('auth.user.is_manager', false)
                ->where('ventas.0.utilidad', null));

        $this->actingAs($admin)->get($url)->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('auth.user.is_manager', true)
                ->where('ventas.0.utilidad', fn ($u) => (float) $u === 40.0));
    }
}
