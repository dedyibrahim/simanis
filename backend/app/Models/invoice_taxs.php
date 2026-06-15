<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class invoice_taxs extends Model
{
    use HasFactory;
    protected $fillable = [
    'id_invoice_tax',
    'id_order',
    'id_order',
    'tax',
    'status_diskon',
    'status_invoice',
    'status_tax',
    'nilai_diskon',
    'diskon',
    'grand_total',
    ];
}
