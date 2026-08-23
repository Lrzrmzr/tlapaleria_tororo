<?php

namespace Tests\Feature;

use App\Console\Commands\BackupDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BackupDatabaseCommandTest extends TestCase
{
    public function test_falla_limpiamente_si_la_conexion_no_es_pgsql(): void
    {
        // El entorno de test corre en sqlite (phpunit.xml) — es la conexión
        // real activa, así que este es un chequeo de comportamiento real,
        // no un mock: nunca debe intentar invocar pg_dump.
        $this->artisan('backup:database')
            ->assertExitCode(1);
    }

    public function test_filesToPrune_descarta_solo_archivos_mas_viejos_que_la_retencion(): void
    {
        $now = Carbon::create(2026, 1, 20, 12, 0, 0);

        $files = [
            'backups/reciente.dump' => $now->copy()->subDays(2)->getTimestamp(),
            'backups/al_limite.dump' => $now->copy()->subDays(14)->getTimestamp(),
            'backups/vencido.dump' => $now->copy()->subDays(15)->getTimestamp(),
            'backups/muy_vencido.dump' => $now->copy()->subDays(30)->getTimestamp(),
        ];

        $result = BackupDatabase::filesToPrune($files, 14, $now);

        $this->assertEqualsCanonicalizing(
            ['backups/vencido.dump', 'backups/muy_vencido.dump'],
            $result
        );
    }

    public function test_filesToPrune_no_borra_nada_si_todo_esta_dentro_de_la_retencion(): void
    {
        $now = Carbon::create(2026, 1, 20, 12, 0, 0);

        $files = [
            'backups/a.dump' => $now->copy()->subDays(1)->getTimestamp(),
            'backups/b.dump' => $now->copy()->subDays(13)->getTimestamp(),
        ];

        $this->assertSame([], BackupDatabase::filesToPrune($files, 14, $now));
    }
}
