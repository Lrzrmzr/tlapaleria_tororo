<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class BranchTransfer extends Model
{
    use SoftDeletes, LogsActivity;

    protected $fillable = ['branch_origen_id', 'branch_destino_id', 'user_id', 'status', 'notas', 'fecha'];

    protected $casts = ['fecha' => 'date'];

    public function branchOrigen(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_origen_id');
    }

    public function branchDestino(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_destino_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function details(): HasMany
    {
        return $this->hasMany(BranchTransferDetail::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => "Traspaso entre sucursales {$eventName}");
    }
}
