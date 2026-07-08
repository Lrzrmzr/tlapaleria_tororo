<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashRegister extends Model
{
    use SoftDeletes;

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
}
