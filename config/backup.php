<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Ruta del binario pg_dump
    |--------------------------------------------------------------------------
    |
    | En Linux/VPS normalmente basta con "pg_dump" (si está en el PATH).
    | En Windows/Laragon casi nunca está en el PATH del sistema — hay que
    | apuntar directo al ejecutable, ej:
    | C:\laragon\bin\postgresql\postgresql-16.4\bin\pg_dump.exe
    |
    */

    'pg_dump_path' => env('BACKUP_PG_DUMP_PATH', 'pg_dump'),

    /*
    |--------------------------------------------------------------------------
    | Retención de backups (días)
    |--------------------------------------------------------------------------
    |
    | Backups con más de este número de días se eliminan automáticamente
    | después de cada respaldo exitoso.
    |
    */

    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 14),

];
