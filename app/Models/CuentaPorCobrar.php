<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class CuentaPorCobrar extends Model
{
    use SoftDeletes, LogsActivity;

    protected $table = 'cuentas_por_cobrar';

    protected $fillable = [
        'customer_id', 'branch_id', 'user_id', 'concepto', 'monto_total',
        'monto_cobrado', 'saldo', 'fecha', 'fecha_vencimiento', 'status', 'notas',
    ];

    protected $casts = [
        'monto_total'      => 'decimal:2',
        'monto_cobrado'    => 'decimal:2',
        'saldo'            => 'decimal:2',
        'fecha'            => 'date',
        'fecha_vencimiento'=> 'date',
    ];

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function branch(): BelongsTo   { return $this->belongsTo(Branch::class); }
    public function user(): BelongsTo     { return $this->belongsTo(User::class); }
    public function cobros(): HasMany     { return $this->hasMany(Cobro::class); }

    public function recalcular(): void
    {
        $cobrado = $this->cobros()->sum('monto');
        $saldo   = $this->monto_total - $cobrado;
        $status  = 'pendiente';
        if ($saldo <= 0)    { $status = 'liquidada'; $saldo = 0; }
        elseif ($cobrado > 0){ $status = 'parcial'; }

        $this->update(['monto_cobrado' => $cobrado, 'saldo' => $saldo, 'status' => $status]);
    }

    public function scopePendiente($query) { return $query->where('status', 'pendiente'); }

    public function scopeVencidas($query)
    {
        return $query->whereIn('status', ['pendiente', 'parcial'])
                     ->whereNotNull('fecha_vencimiento')
                     ->where('fecha_vencimiento', '<', now()->toDateString());
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['concepto', 'monto_total', 'monto_cobrado', 'saldo', 'status', 'customer_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $event) => "Cuenta por cobrar {$event}");
    }
}
