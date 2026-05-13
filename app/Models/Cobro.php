<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Cobro extends Model
{
    use SoftDeletes, LogsActivity;

    protected $table = 'cobros';

    protected $fillable = [
        'cuenta_por_cobrar_id', 'user_id', 'monto', 'fecha_cobro',
        'metodo_pago', 'referencia', 'notas',
    ];

    protected $casts = [
        'monto'      => 'decimal:2',
        'fecha_cobro'=> 'date',
    ];

    public function cuentaPorCobrar(): BelongsTo { return $this->belongsTo(CuentaPorCobrar::class); }
    public function user(): BelongsTo            { return $this->belongsTo(User::class); }

    protected static function booted(): void
    {
        static::created(fn(Cobro $c) => $c->cuentaPorCobrar->recalcular());
        static::deleted(fn(Cobro $c)  => $c->cuentaPorCobrar->recalcular());
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['monto', 'fecha_cobro', 'metodo_pago', 'referencia'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $event) => "Cobro a cliente {$event}");
    }
}
