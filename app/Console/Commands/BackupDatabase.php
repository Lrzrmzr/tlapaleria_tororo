<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class BackupDatabase extends Command
{
    protected $signature = 'backup:database';

    protected $description = 'Genera un respaldo de la base de datos (pg_dump) y elimina los respaldos vencidos';

    private const BACKUP_DIR = 'backups';

    public function handle(): int
    {
        $connection = config('database.default');

        if ($connection !== 'pgsql') {
            $this->error("El respaldo automático solo soporta la conexión 'pgsql'. Conexión activa: '{$connection}'.");

            return self::FAILURE;
        }

        $db = config('database.connections.pgsql');

        Storage::disk('local')->makeDirectory(self::BACKUP_DIR);

        $filename = sprintf('%s-%s.dump', $db['database'], now()->format('Y-m-d_His'));
        $path = Storage::disk('local')->path(self::BACKUP_DIR . '/' . $filename);

        $result = Process::env(['PGPASSWORD' => $db['password']])
            ->run([
                config('backup.pg_dump_path'),
                '-h', $db['host'],
                '-p', (string) $db['port'],
                '-U', $db['username'],
                '-Fc',
                '-f', $path,
                $db['database'],
            ]);

        if ($result->failed()) {
            Log::error('backup:database falló', ['exit_code' => $result->exitCode(), 'error' => $result->errorOutput()]);
            $this->error('El respaldo falló. Revisa storage/logs/laravel.log para más detalle.');

            return self::FAILURE;
        }

        $this->info("Respaldo creado: {$filename}");
        Log::info('backup:database completado', ['archivo' => $filename]);

        $this->pruneOldBackups();

        return self::SUCCESS;
    }

    private function pruneOldBackups(): void
    {
        $files = collect(Storage::disk('local')->files(self::BACKUP_DIR))
            ->mapWithKeys(fn (string $file) => [$file => Storage::disk('local')->lastModified($file)])
            ->all();

        $toDelete = self::filesToPrune($files, (int) config('backup.retention_days'), now());

        foreach ($toDelete as $file) {
            Storage::disk('local')->delete($file);
        }

        if (count($toDelete) > 0) {
            $this->info(count($toDelete) . ' respaldo(s) vencido(s) eliminado(s).');
        }
    }

    /**
     * Determina qué archivos exceden la retención. Función pura, testable sin
     * tocar el disco: recibe [ruta => timestamp unix de última modificación].
     *
     * @param array<string, int> $filesWithMtime
     * @return array<int, string>
     */
    public static function filesToPrune(array $filesWithMtime, int $retentionDays, Carbon $now): array
    {
        $cutoff = $now->copy()->subDays($retentionDays)->getTimestamp();

        return collect($filesWithMtime)
            ->filter(fn (int $mtime) => $mtime < $cutoff)
            ->keys()
            ->all();
    }
}
