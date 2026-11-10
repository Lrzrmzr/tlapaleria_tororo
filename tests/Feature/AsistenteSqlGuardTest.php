<?php

namespace Tests\Feature;

use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\CreatesBusinessFixtures;
use Tests\TestCase;

/**
 * Auditoría run-1: el SQL que genera el LLM del asistente se validaba con
 * regex (solo la tabla inmediata tras FROM/JOIN, columnas por texto) y el
 * filtro de sucursal se concatenaba como texto. El modelo es manipulable por
 * la pregunta del usuario, así que cada caso simula una respuesta maliciosa.
 */
class AsistenteSqlGuardTest extends TestCase
{
    use RefreshDatabase, CreatesBusinessFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.groq.api_key' => 'test-key']);
    }

    private function fakeModel(string $sql): void
    {
        Http::fake([
            'api.groq.com/*' => Http::sequence()
                ->push(['choices' => [['message' => ['content' => json_encode(['sql' => $sql, 'descripcion' => 'x'])]]]])
                ->push(['choices' => [['message' => ['content' => 'resumen']]]]),
        ]);
    }

    private function preguntar(User $user, string $sql): \Illuminate\Testing\TestResponse
    {
        $this->fakeModel($sql);

        return $this->actingAs($user)->postJson('/asistente', ['pregunta' => 'x'])->assertOk();
    }

    /** @return array{0: \App\Models\Branch, 1: \App\Models\Branch, 2: User} */
    private function dosSucursalesConVentas(): array
    {
        $a = $this->makeBranch(['code' => 'A']);
        $b = $this->makeBranch(['code' => 'B']);
        $trabajador = $this->makeUser($a, 'trabajador');

        foreach ([$a, $b] as $branch) {
            Sale::create([
                'branch_id' => $branch->id,
                'user_id'   => $trabajador->id,
                'total'     => 100,
                'status'    => 'completed',
            ]);
        }

        return [$a, $b, $trabajador];
    }

    public static function payloadsDeTablasNoPermitidas(): array
    {
        return [
            'coma en FROM'          => ['SELECT users.* FROM branches, users LIMIT 30'],
            'identificador citado'  => ['SELECT * FROM "users" LIMIT 30'],
            'subconsulta'           => ['SELECT (SELECT password FROM users LIMIT 1) AS p FROM branches'],
            'esquema calificado'    => ['SELECT * FROM main.users'],
            'catálogo del sistema'  => ['SELECT name FROM sqlite_master'],
            'tokens oauth'          => ['SELECT b.id, t.* FROM branches b, oauth_access_tokens t'],
        ];
    }

    /** @dataProvider payloadsDeTablasNoPermitidas */
    public function test_no_permite_leer_tablas_fuera_de_la_allowlist(string $sql): void
    {
        $user = $this->makeUser($this->makeBranch(), 'trabajador');

        $response = $this->preguntar($user, $sql);

        $this->assertSame([], $response->json('datos'));
        $this->assertNotContains('password', $response->json('columnas'));
    }

    public static function payloadsDeEvasionDeSucursal(): array
    {
        return [
            'coma en FROM'      => ['SELECT s.id, s.branch_id FROM products p, sales s'],
            'OR 1=1'            => ['SELECT id, branch_id FROM sales WHERE branch_id = {A} OR 1=1'],
            'subconsulta'       => ['SELECT id, branch_id FROM sales WHERE id IN (SELECT id FROM sales)'],
            'sin filtro'        => ['SELECT id, branch_id FROM sales ORDER BY id DESC LIMIT 30'],
            'detalle de venta'  => ['SELECT d.id, s.branch_id FROM sale_details d JOIN sales s ON s.id = d.sale_id'],
        ];
    }

    /** @dataProvider payloadsDeEvasionDeSucursal */
    public function test_un_trabajador_solo_ve_datos_de_su_sucursal(string $sql): void
    {
        [$a, $b, $trabajador] = $this->dosSucursalesConVentas();

        $response = $this->preguntar($trabajador, str_replace('{A}', (string) $a->id, $sql));

        $branchIds = collect($response->json('datos'))->pluck('branch_id')->map(fn ($v) => (int) $v)->unique()->values();
        $this->assertNotContains($b->id, $branchIds->all());
    }

    public function test_root_si_ve_todas_las_sucursales(): void
    {
        [$a, $b] = $this->dosSucursalesConVentas();
        $root = User::create(['name' => 'Root', 'email' => 'root@example.test', 'password' => 'password', 'active' => true]);
        $root->roles()->attach(\App\Models\Role::firstOrCreate(['name' => 'root'])->id);

        $response = $this->preguntar($root, 'SELECT id, branch_id FROM sales');

        $this->assertCount(2, $response->json('datos'));
    }

    public function test_un_trabajador_si_obtiene_sus_propias_ventas(): void
    {
        [$a, , $trabajador] = $this->dosSucursalesConVentas();

        $response = $this->preguntar($trabajador, 'SELECT id, branch_id, total FROM sales WHERE status = \'completed\'');

        $this->assertCount(1, $response->json('datos'));
        $this->assertSame($a->id, (int) $response->json('datos.0.branch_id'));
    }

    public static function payloadsConEfectos(): array
    {
        return [
            'setval'               => ["SELECT setval('sales_id_seq', 1)"],
            'pg_sleep'             => ['SELECT pg_sleep(5)'],
            'pg_terminate_backend' => ['SELECT pg_terminate_backend(1)'],
            'query_to_xml'         => ["SELECT query_to_xml('select * from users', true, true, '')"],
            'select into'          => ['SELECT * INTO copia FROM products'],
            'sentencias múltiples' => ['SELECT 1; DELETE FROM sales'],
            'comentario'           => ['SELECT id FROM sales -- branch_id = 1'],
            'with'                 => ['WITH x AS (SELECT 1) SELECT * FROM x'],
        ];
    }

    /** @dataProvider payloadsConEfectos */
    public function test_rechaza_funciones_con_efectos_y_sentencias_peligrosas(string $sql): void
    {
        [, , $trabajador] = $this->dosSucursalesConVentas();

        $response = $this->preguntar($trabajador, $sql);

        $this->assertSame([], $response->json('datos'));
        $this->assertSame(2, Sale::count());
    }

    public function test_quita_columnas_sensibles_del_resultado(): void
    {
        $user = $this->makeUser($this->makeBranch(), 'trabajador');

        $response = $this->preguntar($user, "SELECT name, 'x' AS remember_token, 'y' AS api_key FROM branches");

        $this->assertNotEmpty($response->json('datos'));
        $this->assertSame(['name'], $response->json('columnas'));
    }

    public function test_limita_las_filas_devueltas(): void
    {
        $branch = $this->makeBranch();
        $user = $this->makeUser($branch, 'trabajador');
        for ($i = 0; $i < 120; $i++) {
            $this->makeProduct(['name' => 'Producto ' . $i]);
        }

        $response = $this->preguntar($user, 'SELECT id, name FROM products');

        $this->assertCount(\App\Services\AsistenteSqlGuard::MAX_ROWS, $response->json('datos'));
    }
}
