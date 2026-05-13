<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AsistenteController extends Controller
{
    // Tablas del negocio permitidas para consulta
    private const ALLOWED_TABLES = [
        'products', 'branch_inventory', 'branches', 'sales', 'sale_details',
        'gastos', 'customers', 'suppliers', 'cuentas_por_cobrar', 'cuentas_por_pagar',
        'cobros', 'pagos_proveedor', 'categories', 'category_product', 'product_supplier',
    ];

    // Columnas que NUNCA deben ser retornadas
    private const FORBIDDEN_COLUMNS = [
        'password', 'remember_token', 'token', 'secret', 'api_key',
        'access_token', 'refresh_token', 'client_secret',
    ];

    private function validateSqlSafety(string $sql): ?string
    {
        $sqlNorm = strtoupper(preg_replace('/\s+/', ' ', trim($sql)));

        if (!str_starts_with($sqlNorm, 'SELECT')) {
            return 'Solo se permiten consultas SELECT.';
        }

        $forbidden = ['INSERT', 'UPDATE', 'DELETE', 'DROP', 'TRUNCATE', 'CREATE',
                      'ALTER', 'GRANT', 'REVOKE', 'EXEC', 'EXECUTE', 'CALL',
                      'INTO OUTFILE', 'INTO DUMPFILE', 'LOAD_FILE', 'SLEEP', 'BENCHMARK'];
        foreach ($forbidden as $kw) {
            if (preg_match('/\b' . preg_quote($kw, '/') . '\b/', $sqlNorm)) {
                return "Operación no permitida: {$kw}";
            }
        }

        // Allowlist de tablas — extrae nombres tras FROM/JOIN
        preg_match_all('/\b(?:FROM|JOIN)\s+([a-z_][a-z0-9_]*)/i', $sql, $matches);
        foreach (array_map('strtolower', $matches[1] ?? []) as $table) {
            if (!in_array($table, self::ALLOWED_TABLES, true)) {
                return "Acceso denegado a la tabla: {$table}";
            }
        }

        // Columnas prohibidas (datos sensibles)
        $sqlLower = strtolower($sql);
        foreach (self::FORBIDDEN_COLUMNS as $col) {
            if (preg_match('/\b' . preg_quote($col, '/') . '\b/', $sqlLower)) {
                return "Columna no permitida: {$col}";
            }
        }

        return null;
    }

    public function consulta(Request $request)
    {
        $request->validate(['pregunta' => 'required|string|max:600']);

        $pregunta     = trim($request->pregunta);
        $branchId     = $request->user_branch_id;
        $isRoot       = $request->is_root ?? false;
        $apiKey       = config('services.groq.api_key');

        if (!$apiKey) {
            return response()->json([
                'respuesta' => 'El asistente de IA no está configurado. Agrega GROQ_API_KEY en el archivo .env.',
                'datos'     => [],
                'columnas'  => [],
            ], 200);
        }

        // ── 1. Generar SQL via Groq ───────────────────────────────────────────
        try {
            $groqResp = Http::withToken($apiKey)
                ->timeout(25)
                ->post(config('services.groq.url'), [
                    'model'           => config('services.groq.model'),
                    'temperature'     => 0.05,
                    'max_tokens'      => 600,
                    'response_format' => ['type' => 'json_object'],
                    'messages'        => [
                        ['role' => 'system', 'content' => $this->systemPrompt($branchId, $isRoot)],
                        ['role' => 'user',   'content' => $pregunta],
                    ],
                ]);

            if (!$groqResp->successful()) {
                Log::error('Groq API error', ['status' => $groqResp->status(), 'body' => $groqResp->body()]);
                return response()->json(['error' => 'Error al contactar el modelo de IA. Intenta de nuevo.'], 502);
            }

            $parsed      = json_decode($groqResp->json('choices.0.message.content'), true);
            $sql         = $parsed['sql'] ?? null;
            $descripcion = $parsed['descripcion'] ?? '';

        } catch (\Exception $e) {
            Log::error('Groq request failed', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'No se pudo conectar con el modelo de IA.'], 502);
        }

        // ── 2. Sin SQL → respuesta directa ───────────────────────────────────
        if (!$sql) {
            return response()->json([
                'respuesta' => 'No encontré información en el sistema para responder esa pregunta. ' .
                               'Puedo ayudarte con precios, existencias, ventas, gastos, deudas y clientes.',
                'datos'     => [],
                'columnas'  => [],
            ]);
        }

        // ── 3. Validar SQL (allowlist de tablas + columnas prohibidas) ───────
        $sqlError = $this->validateSqlSafety($sql);
        if ($sqlError !== null) {
            Log::warning('Asistente SQL rechazado', ['sql' => $sql, 'reason' => $sqlError]);
            return response()->json([
                'respuesta' => 'Esa consulta no está permitida. Solo puedo consultar información del negocio: precios, stock, ventas, gastos, deudas y clientes.',
                'datos'     => [],
                'columnas'  => [],
            ]);
        }

        // ── 4. Ejecutar SQL ───────────────────────────────────────────────────
        try {
            $rows     = DB::select($sql);
            $datos    = collect($rows)->map(fn($r) => (array) $r)->toArray();
            $columnas = !empty($datos) ? array_keys($datos[0]) : [];
        } catch (\Exception $e) {
            Log::error('Asistente SQL error', ['sql' => $sql, 'error' => $e->getMessage()]);
            return response()->json([
                'respuesta' => 'Hubo un error al consultar los datos. Por favor reformula tu pregunta.',
                'datos'     => [],
                'columnas'  => [],
            ]);
        }

        // ── 5. Resumir resultados en lenguaje natural ─────────────────────────
        $respuesta = $this->resumirResultados($apiKey, $pregunta, $descripcion, $datos);

        return response()->json([
            'respuesta' => $respuesta,
            'datos'     => $datos,
            'columnas'  => $columnas,
        ]);
    }

    // ── System prompt con el esquema completo ─────────────────────────────────
    private function systemPrompt(int|null $branchId, bool $isRoot): string
    {
        $branchClause = $branchId && !$isRoot
            ? "IMPORTANTE: El usuario pertenece a la sucursal con branch_id = {$branchId}. Filtra SIEMPRE por branch_id = {$branchId} en: sales, gastos, branch_inventory, cuentas_por_cobrar, cuentas_por_pagar."
            : "El usuario es administrador total y puede ver datos de todas las sucursales.";

        return <<<PROMPT
Eres el asistente inteligente de una tlapalería (ferretería pequeña). Tienes acceso a su base de datos PostgreSQL.
Tu función es responder preguntas del personal sobre el negocio: precios, stock, ventas, deudas, gastos, clientes, etc.

{$branchClause}

ESQUEMA DE LA BASE DE DATOS:

products — catálogo de productos
  id, name (varchar), barcode (varchar), price (decimal — precio de venta), cost (decimal — costo), supplier_id, active (boolean), deleted_at

branch_inventory — inventario por sucursal
  id, branch_id, product_id, stock (integer — existencia actual), min_stock, max_stock, cost (decimal), active (boolean)

branches — sucursales del negocio
  id, name (varchar), code (varchar), active (boolean)

sales — ventas realizadas
  id, user_id, branch_id, customer_id, total (decimal), status ('completed'), created_at

sale_details — líneas de cada venta
  id, sale_id, product_id, tipo_venta ('libre' o 'catalogo'), descripcion (varchar), quantity (integer), price (decimal), subtotal (decimal)

gastos — gastos del negocio
  id, descripcion (varchar), monto (decimal), branch_id, user_id, created_at, deleted_at

customers — clientes registrados
  id, name (varchar), phone (varchar), active (boolean), deleted_at

suppliers — proveedores
  id, name (varchar), active (boolean), deleted_at

cuentas_por_cobrar — créditos otorgados a clientes
  id, customer_id, branch_id, user_id, concepto (varchar), monto_total (decimal), monto_cobrado (decimal), saldo (decimal), fecha (date), fecha_vencimiento (date), status ('pendiente','parcial','liquidada'), deleted_at

cuentas_por_pagar — deudas con proveedores
  id, supplier_id, branch_id, user_id, numero_nota (varchar), concepto (varchar), monto_total (decimal), monto_pagado (decimal), saldo (decimal), fecha_nota (date), fecha_vencimiento (date), status ('pendiente','parcial','pagada'), deleted_at

cobros — pagos recibidos de clientes
  id, cuenta_por_cobrar_id, user_id, monto (decimal), fecha_cobro (date), metodo_pago ('efectivo','transferencia'), deleted_at

pagos_proveedor — pagos realizados a proveedores
  id, cuenta_por_pagar_id, user_id, monto (decimal), fecha_pago (date), metodo_pago ('efectivo','transferencia','cheque'), deleted_at

REGLAS ESTRICTAS:
1. SOLO genera SELECT. Nunca INSERT, UPDATE, DELETE, DROP, ALTER, TRUNCATE ni nada que modifique datos.
2. Usa ILIKE '%texto%' para buscar nombres de productos, clientes o proveedores (búsqueda parcial e insensible a mayúsculas).
3. Siempre excluye registros eliminados: agrega "AND tabla.deleted_at IS NULL" en las tablas que tienen ese campo.
4. Limita con LIMIT 30 salvo que el usuario pida explícitamente más.
5. Para preguntar por precio + stock de un producto, haz JOIN entre products y branch_inventory.
6. Si la pregunta no tiene relación con el negocio o no la puedes resolver con estas tablas, devuelve sql: null.

Responde ÚNICAMENTE con JSON válido (sin backticks, sin texto adicional):
{"sql": "SELECT ...", "descripcion": "Frase corta en español de qué estás buscando"}
PROMPT;
    }

    // ── Segunda llamada: resumir los resultados en lenguaje natural ───────────
    private function resumirResultados(string $apiKey, string $pregunta, string $descripcion, array $datos): string
    {
        if (empty($datos)) {
            return trim($descripcion) ? "{$descripcion} — No se encontraron resultados." : "No se encontraron resultados para tu consulta.";
        }

        // Serializar resultados como texto (máx 20 filas para no exceder tokens)
        $muestra  = array_slice($datos, 0, 20);
        $datosStr = json_encode($muestra, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $total    = count($datos);
        $nota     = $total > 20 ? " (mostrando 20 de {$total} resultados)" : '';

        $prompt = "El usuario preguntó: \"{$pregunta}\"\n\n"
            . "Se obtuvieron los siguientes datos{$nota}:\n{$datosStr}\n\n"
            . "Resume la respuesta en español, de forma directa y útil para alguien en una tlapalería. "
            . "Menciona los datos más relevantes. Máximo 4 líneas. Sin formalidades.";

        try {
            $resp = Http::withToken($apiKey)
                ->timeout(20)
                ->post(config('services.groq.url'), [
                    'model'       => config('services.groq.model'),
                    'temperature' => 0.3,
                    'max_tokens'  => 250,
                    'messages'    => [
                        ['role' => 'system', 'content' => 'Eres un asistente conciso de una tlapalería. Responde en español, directo al punto.'],
                        ['role' => 'user',   'content' => $prompt],
                    ],
                ]);

            if ($resp->successful()) {
                return trim($resp->json('choices.0.message.content') ?? $descripcion);
            }
        } catch (\Exception $e) {
            Log::warning('Groq summarize failed', ['error' => $e->getMessage()]);
        }

        // Fallback si falla el resumen
        return $descripcion . ($total > 0 ? " — Se encontraron {$total} resultado(s)." : ' — Sin resultados.');
    }
}
