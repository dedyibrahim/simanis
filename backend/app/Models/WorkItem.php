<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class WorkItem extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = ['due_date' => 'date'];

    public function links() { return $this->hasMany(WorkItemLink::class); }
    public function checklists() { return $this->hasMany(WorkItemChecklist::class)->orderBy('sort_order'); }
    public function activities() { return $this->hasMany(WorkItemActivity::class)->latest(); }
    public function costs() { return $this->hasMany(WorkItemCost::class); }
    public function invoices() { return $this->hasMany(WorkItemInvoice::class)->latest(); }
}
