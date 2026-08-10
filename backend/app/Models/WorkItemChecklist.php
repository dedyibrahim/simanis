<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class WorkItemChecklist extends Model { protected $guarded = []; protected $casts = ['is_required' => 'boolean', 'is_completed' => 'boolean', 'completed_at' => 'datetime']; }
