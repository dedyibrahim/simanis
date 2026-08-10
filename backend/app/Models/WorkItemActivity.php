<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class WorkItemActivity extends Model { protected $guarded = []; protected $casts = ['metadata' => 'array']; }
