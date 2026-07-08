<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BranchTransferDetail extends Model
{
    protected $fillable = ['branch_transfer_id', 'product_id', 'quantity'];

    public function branchTransfer(): BelongsTo
    {
        return $this->belongsTo(BranchTransfer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
