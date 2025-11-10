<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'payment_method',
        'midtrans_transaction_id',
        'midtrans_order_id',
        'snap_token',
        'amount',
        'status',
        'payment_url',
        'qr_code_url',
        'deeplink_url',
        'va_number',
        'bank',
        'bill_key',
        'biller_code',
        'raw_response',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'raw_response' => 'array',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function notifications()
    {
        return $this->hasMany(PaymentNotification::class);
    }
}

