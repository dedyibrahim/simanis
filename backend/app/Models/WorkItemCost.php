<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class WorkItemCost extends Model { protected $guarded = []; protected $casts = ['quantity' => 'decimal:2', 'taxable' => 'boolean']; }
