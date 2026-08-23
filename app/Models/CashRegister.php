<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class CashRegister extends Model
{
    use SoftDeletes, LogsActivity;

    protected $fillable = [
        'branch_id', 'user_id', 'fondo_inicial', 'fecha_apertura',
        'closed_by', 'fecha_cierre', 'ventas_efectivo', 'gastos_periodo',
        'cobros_efectivo', 'pagos_efectivo', 'efectivo_esperado',
        'efectivo_contado', 'diferencia', 'status', 'notas',
    ];

    protected $casts = [
        'fondo_inicial'     => 'decimal:2',
        'ventas_efectivo'   => 'decimal:2',
        'gastos_periodo'    => 'decimal:2',
        'cobros_efectivo'   => 'decimal:2',
        'pagos_efectivo'    => 'decimal:2',
        'efectivo_esperado' => 'decimal:2',
        'efectivo_contado'  => 'decimal:2',
        'diferencia'        => 'decimal:2',
        'fecha_apertura'    => 'datetime',
        'fecha_cierre'      => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    protected static function booted(): void
    {
        // open_marker es NULL salvo en la caja abierta de cada sucursal (NULL no
        // colisiona con el índice único, así que solo puede haber una fila con
        // open_marker = branch_id por sucursal — ver migración open_marker).
        static::saving(function (self $caja) {
            $caja->open_marker = $caja->status === 'abierta' ? $caja->branch_id : null;
        });

        static::deleting(function (self $caja) {
            if (!$caja->isForceDeleting()) {
                static::withoutEvents(fn () => $caja->newQueryWithoutScopes()
                    ->whereKey($caja->getKey())
                    ->update(['open_marker' => null]));
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'branch_id', 'fondo_inicial', 'fecha_apertura', 'fecha_cierre',
                'efectivo_esperado', 'efectivo_contado', 'diferencia', 'status', 'notas',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => "Corte de caja {$eventName}");
    }
}
