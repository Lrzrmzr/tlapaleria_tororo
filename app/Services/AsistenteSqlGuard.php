<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Valida y ejecuta el SQL que genera el LLM del asistente.
 *
 * El SQL del modelo se trata como entrada NO confiable (el usuario puede
 * manipular al modelo con su pregunta). Por eso la seguridad no depende de
 * que el modelo "obedezca" el prompt, sino de tres capas:
 *
 *  1. validate(): rechaza todo lo que no sea un SELECT simple sobre tablas
 *     del negocio (sin comentarios, comillas dobles, ';', SELECT INTO, ni
 *     funciones fuera de una allowlist, ni referencias a tablas no permitidas).
 *  2. run(): para usuarios no-root, las tablas con datos por sucursal se
 *     reemplazan con CTEs ya filtradas por branch_id (parámetro enlazado), así
 *     que OR 1=1, comas, subconsultas o alias no pueden ver otras sucursales.
 *  3. run(): ejecuta en modo solo lectura, con tope de filas y quitando del
 *     resultado cualquier columna con nombre sensible.
 */
class AsistenteSqlGuard
{
    public const MAX_ROWS = 100;

    public const ALLOWED_TABLES = [
        'products', 'branch_inventory', 'branches', 'sales', 'sale_details',
        'gastos', 'customers', 'suppliers', 'cuentas_por_cobrar', 'cuentas_por_pagar',
        'cobros', 'pagos_proveedor', 'categories', 'category_product', 'product_supplier',
    ];

    /** Funciones SQL de solo lectura que el modelo puede usar. */
    private const ALLOWED_FUNCTIONS = [
        'count', 'sum', 'avg', 'min', 'max', 'coalesce', 'nullif', 'round', 'abs',
        'floor', 'ceil', 'lower', 'upper', 'trim', 'length', 'substr', 'substring',
        'concat', 'greatest', 'least', 'cast', 'date', 'extract', 'date_trunc',
        'to_char', 'now', 'strftime', 'ifnull',
    ];

    /** Palabras SQL que pueden ir seguidas de "(" sin ser funciones. */
    private const PAREN_KEYWORDS = [
        'select', 'from', 'where', 'and', 'or', 'not', 'in', 'exists', 'as', 'on',
        'join', 'by', 'having', 'when', 'then', 'else', 'case', 'between', 'over',
        'filter', 'distinct', 'all', 'any', 'like', 'ilike', 'is', 'values', 'union',
    ];

    private const FORBIDDEN_KEYWORDS = [
        'INSERT', 'UPDATE', 'DELETE', 'DROP', 'TRUNCATE', 'CREATE', 'ALTER', 'GRANT',
        'REVOKE', 'EXEC', 'EXECUTE', 'CALL', 'INTO', 'COPY', 'PRAGMA', 'ATTACH',
        'DETACH', 'VACUUM', 'LOCK', 'SET', 'RESET', 'LISTEN', 'NOTIFY', 'DO',
        'LOAD', 'WITH', 'RECURSIVE',
    ];

    /** Esquemas/catálogos de sistema que nunca deben referenciarse. */
    private const FORBIDDEN_IDENTIFIERS = [
        'public', 'main', 'temp', 'pg_catalog', 'information_schema',
        'sqlite_master', 'sqlite_schema', 'sqlite_temp_master',
    ];

    /** Columnas cuyo nombre indica datos sensibles: se quitan del resultado. */
    private const SENSITIVE_COLUMN_PATTERN = '/password|token|secret|api_key/i';

    /**
     * Tablas con datos por sucursal y el SQL que las filtra. {s} es el prefijo
     * de esquema real (p. ej. "public." o "main.") para que la CTE lea la tabla
     * física y no a sí misma. Los "?" se enlazan con el branch_id del usuario.
     */
    private const BRANCH_SCOPED = [
        'sales'              => 'SELECT * FROM {s}sales WHERE branch_id = ?',
        'gastos'             => 'SELECT * FROM {s}gastos WHERE branch_id = ?',
        'branch_inventory'   => 'SELECT * FROM {s}branch_inventory WHERE branch_id = ?',
        'cuentas_por_cobrar' => 'SELECT * FROM {s}cuentas_por_cobrar WHERE branch_id = ?',
        'cuentas_por_pagar'  => 'SELECT * FROM {s}cuentas_por_pagar WHERE branch_id = ?',
        'sale_details'       => 'SELECT * FROM {s}sale_details WHERE sale_id IN (SELECT id FROM {s}sales WHERE branch_id = ?)',
        'cobros'             => 'SELECT * FROM {s}cobros WHERE cuenta_por_cobrar_id IN (SELECT id FROM {s}cuentas_por_cobrar WHERE branch_id = ?)',
        'pagos_proveedor'    => 'SELECT * FROM {s}pagos_proveedor WHERE cuenta_por_pagar_id IN (SELECT id FROM {s}cuentas_por_pagar WHERE branch_id = ?)',
    ];

    /**
     * Devuelve null si el SQL es aceptable, o el motivo del rechazo.
     */
    public function validate(string $sql): ?string
    {
        $sql = $this->normalize($sql);

        if ($sql === '') {
            return 'Consulta vacía.';
        }

        // Caracteres que permiten esconder SQL de la validación: comentarios,
        // identificadores entre comillas, sentencias múltiples, dollar-quoting.
        foreach (['--', '/*', '*/', ';', '"', '`', '\\', '$', '[', ']'] as $token) {
            if (str_contains($sql, $token)) {
                return 'La consulta contiene caracteres no permitidos.';
            }
        }

        if (!preg_match('/^select\b/i', $sql)) {
            return 'Solo se permiten consultas SELECT.';
        }

        // Analizar el código sin los literales de texto ('...').
        $code = preg_replace("/'(?:[^']|'')*'/", "''", $sql);
        if (substr_count($code, "'") % 2 !== 0) {
            return 'Literal de texto sin cerrar.';
        }

        $upper = strtoupper($code);
        foreach (self::FORBIDDEN_KEYWORDS as $kw) {
            if (preg_match('/\b' . $kw . '\b/', $upper)) {
                return "Operación no permitida: {$kw}";
            }
        }

        // Funciones: todo identificador seguido de "(" debe estar permitido.
        preg_match_all('/\b([a-z_][a-z0-9_]*)\s*\(/i', $code, $calls);
        foreach (array_map('strtolower', $calls[1]) as $fn) {
            if (!in_array($fn, self::PAREN_KEYWORDS, true) && !in_array($fn, self::ALLOWED_FUNCTIONS, true)) {
                return "Función no permitida: {$fn}";
            }
        }

        // Ningún identificador puede nombrar una tabla fuera de la allowlist ni
        // un esquema de sistema (cubre comas, subconsultas y "esquema.tabla").
        $forbiddenTables = $this->forbiddenTableNames();
        preg_match_all('/\b([a-z_][a-z0-9_]*)\b/i', $code, $idents);
        foreach (array_unique(array_map('strtolower', $idents[1])) as $ident) {
            if (in_array($ident, self::FORBIDDEN_IDENTIFIERS, true)
                || str_starts_with($ident, 'pg_')
                || str_starts_with($ident, 'sqlite_')
                || isset($forbiddenTables[$ident])) {
                return "Acceso denegado a: {$ident}";
            }
        }

        return null;
    }

    /**
     * Ejecuta un SQL ya validado y devuelve las filas como arreglos.
     *
     * @return array<int, array<string, mixed>>
     */
    public function run(string $sql, ?int $branchId, bool $isRoot): array
    {
        $sql = $this->normalize($sql);
        $bindings = [];
        $prefix = '';

        if (!$isRoot) {
            if ($branchId === null) {
                return [];
            }

            $schema = $this->schemaPrefix();
            $ctes = [];
            foreach (self::BRANCH_SCOPED as $table => $definition) {
                $ctes[] = $table . ' AS (' . str_replace('{s}', $schema, $definition) . ')';
                $bindings[] = $branchId;
            }
            $prefix = 'WITH ' . implode(', ', $ctes) . ' ';
        }

        $wrapped = $prefix . 'SELECT * FROM (' . $sql . ') AS asistente_q LIMIT ' . self::MAX_ROWS;

        $rows = $this->readOnly(fn () => DB::select($wrapped, $bindings));

        return array_map(function ($row) {
            $row = (array) $row;
            foreach (array_keys($row) as $column) {
                if (preg_match(self::SENSITIVE_COLUMN_PATTERN, (string) $column)) {
                    unset($row[$column]);
                }
            }
            return $row;
        }, $rows);
    }

    private function normalize(string $sql): string
    {
        // Un único ';' final es común en la salida del modelo; cualquier otro
        // ';' se rechaza en validate().
        return rtrim(trim($sql), "; \t\n\r");
    }

    /** @return array<string, true> */
    private function forbiddenTableNames(): array
    {
        $names = [];
        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            $table = strtolower($table);
            if (!in_array($table, self::ALLOWED_TABLES, true)) {
                $names[$table] = true;
            }
        }

        // Aunque la tabla no exista en este entorno, nunca se permiten.
        foreach (['users', 'roles', 'role_user', 'sessions', 'password_reset_tokens',
                  'personal_access_tokens', 'activity_log', 'system_settings', 'jobs',
                  'failed_jobs', 'cache', 'migrations'] as $table) {
            $names[$table] = true;
        }
        foreach (['oauth_access_tokens', 'oauth_refresh_tokens', 'oauth_clients',
                  'oauth_auth_codes', 'oauth_device_codes'] as $table) {
            $names[$table] = true;
        }

        return $names;
    }

    private function schemaPrefix(): string
    {
        $connection = DB::connection();

        if ($connection->getDriverName() === 'pgsql') {
            $searchPath = $connection->getConfig('search_path') ?: 'public';
            $schema = trim(explode(',', is_array($searchPath) ? $searchPath[0] : $searchPath)[0]);
            return preg_match('/^[a-z_][a-z0-9_]*$/i', $schema) ? $schema . '.' : 'public.';
        }

        if ($connection->getDriverName() === 'sqlite') {
            return 'main.';
        }

        return '';
    }

    /**
     * Ejecuta el callback sin permitir escrituras y con límite de tiempo.
     */
    private function readOnly(callable $callback): array
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        if ($driver === 'pgsql') {
            // SET TRANSACTION solo es válido como primera sentencia de una
            // transacción real (no dentro de un savepoint).
            $isOuterTransaction = $connection->transactionLevel() === 0;
            $connection->beginTransaction();
            try {
                if ($isOuterTransaction) {
                    $connection->statement('SET TRANSACTION READ ONLY');
                }
                $connection->statement("SET LOCAL statement_timeout = '5s'");
                return $callback();
            } finally {
                $connection->rollBack();
            }
        }

        if ($driver === 'sqlite') {
            $connection->statement('PRAGMA query_only = ON');
            try {
                return $callback();
            } finally {
                $connection->statement('PRAGMA query_only = OFF');
            }
        }

        return $callback();
    }
}
