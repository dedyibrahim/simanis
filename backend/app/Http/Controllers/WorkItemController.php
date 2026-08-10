<?php

namespace App\Http\Controllers;

use App\Models\WorkItem;
use App\Models\WorkItemActivity;
use App\Models\WorkItemChecklist;
use App\Models\WorkItemCost;
use App\Models\WorkItemInvoice;
use App\Models\WorkItemLink;
use App\Models\detail_pesanan;
use App\Models\invoice_non_taxs;
use App\Models\invoice_taxs;
use App\Models\orders;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class WorkItemController extends Controller
{
    private const STATUSES = ['draft', 'verification', 'in_progress', 'waiting_client', 'ready_to_sign', 'completed', 'ready_to_bill', 'invoiced'];

    private const TRANSITIONS = [
        'draft' => ['verification'],
        'verification' => ['draft', 'in_progress', 'waiting_client'],
        'in_progress' => ['waiting_client', 'ready_to_sign', 'completed'],
        'waiting_client' => ['verification', 'in_progress'],
        'ready_to_sign' => ['in_progress', 'completed'],
        'completed' => ['in_progress', 'ready_to_bill'],
        'ready_to_bill' => ['completed', 'invoiced'],
        'invoiced' => [],
    ];

    public function index(Request $request)
    {
        $query = WorkItem::query()
            ->leftJoin('data_clients', 'work_items.client_id', '=', 'data_clients.id_client')
            ->leftJoin('users as assignees', 'work_items.assignee_id', '=', 'assignees.id_user')
            ->select('work_items.*', 'data_clients.nama_client', 'assignees.nama_lengkap as assignee_name')
            ->withCount(['links', 'checklists', 'costs'])
            ->latest('work_items.id');

        if ($status = trim((string) $request->query('status'))) $query->where('work_items.status', $status);
        if ($assignee = trim((string) $request->query('assignee_id'))) $query->where('work_items.assignee_id', $assignee);
        if ($search = trim((string) $request->query('search'))) {
            $query->where(function ($builder) use ($search) {
                $builder->where('work_items.work_number', 'like', "%{$search}%")
                    ->orWhere('work_items.title', 'like', "%{$search}%")
                    ->orWhere('data_clients.nama_client', 'like', "%{$search}%");
            });
        }

        return response()->json(['status' => true, 'message' => 'Workflow pekerjaan berhasil dimuat.', 'data' => $query->limit(500)->get()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->workRules());
        $item = DB::transaction(function () use ($data, $request) {
            $item = WorkItem::create($data + [
                'work_number' => $this->nextWorkNumber(),
                'created_by' => optional($request->user())->id_user,
            ]);
            $this->activity($item, 'created', 'Pekerjaan dibuat.', null, $item->status, $request);
            return $item;
        });

        return response()->json(['status' => true, 'message' => 'Pekerjaan berhasil dibuat.', 'data' => $this->detail($item)], 201);
    }

    public function show(WorkItem $workItem)
    {
        return response()->json(['status' => true, 'message' => 'Detail pekerjaan berhasil dimuat.', 'data' => $this->detail($workItem)]);
    }

    public function update(Request $request, WorkItem $workItem)
    {
        $data = $request->validate($this->workRules(true));
        $workItem->update($data);
        $this->activity($workItem, 'updated', 'Informasi pekerjaan diperbarui.', null, null, $request);
        return response()->json(['status' => true, 'message' => 'Pekerjaan berhasil diperbarui.', 'data' => $this->detail($workItem)]);
    }

    public function transition(Request $request, WorkItem $workItem)
    {
        $data = $request->validate(['status' => ['required', Rule::in(self::STATUSES)], 'note' => ['nullable', 'string', 'max:1000']]);
        $from = $workItem->status;
        $to = $data['status'];
        if (!in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            return response()->json(['status' => false, 'message' => "Status tidak dapat dipindahkan dari {$from} ke {$to}."], 422);
        }
        if (in_array($to, ['completed', 'ready_to_bill'], true) && $workItem->checklists()->where('is_required', true)->where('is_completed', false)->exists()) {
            return response()->json(['status' => false, 'message' => 'Checklist wajib harus selesai sebelum melanjutkan status.'], 422);
        }
        $workItem->update(['status' => $to, 'billing_status' => $to === 'ready_to_bill' ? 'ready' : $workItem->billing_status]);
        $this->activity($workItem, 'status_changed', $data['note'] ?? "Status diubah ke {$to}.", $from, $to, $request);
        return response()->json(['status' => true, 'message' => 'Status workflow berhasil diperbarui.', 'data' => $this->detail($workItem)]);
    }

    public function addChecklist(Request $request, WorkItem $workItem)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:500'],
            'item_type' => ['nullable', Rule::in(['checklist', 'permit'])],
            'is_required' => ['nullable', 'boolean'],
        ]);
        $row = $workItem->checklists()->create($data + ['sort_order' => ((int) $workItem->checklists()->max('sort_order')) + 1]);
        $this->activity($workItem, 'checklist_added', "Checklist ditambahkan: {$row->title}", null, null, $request);
        return response()->json(['status' => true, 'message' => 'Checklist ditambahkan.', 'data' => $row], 201);
    }

    public function toggleChecklist(Request $request, WorkItem $workItem, WorkItemChecklist $checklist)
    {
        abort_unless($checklist->work_item_id === $workItem->id, 404);
        $completed = !$checklist->is_completed;
        $checklist->update(['is_completed' => $completed, 'completed_by' => $completed ? optional($request->user())->id_user : null, 'completed_at' => $completed ? now() : null]);
        $this->activity($workItem, 'checklist_updated', ($completed ? 'Checklist selesai: ' : 'Checklist dibuka kembali: ').$checklist->title, null, null, $request);
        return response()->json(['status' => true, 'message' => 'Checklist diperbarui.', 'data' => $checklist]);
    }

    public function addLink(Request $request, WorkItem $workItem)
    {
        $data = $request->validate([
            'module_type' => ['required', Rule::in(['buku_akta_notaris', 'buku_legalisasi', 'buku_waarmerking', 'buku_ppat', 'custom'])],
            'record_id' => ['nullable', 'string', 'max:40'], 'label' => ['required', 'string', 'max:500'],
            'relationship_type' => ['nullable', 'string', 'max:30'], 'metadata' => ['nullable', 'array'],
        ]);
        if ($data['module_type'] !== 'custom' && empty($data['record_id'])) return response()->json(['status' => false, 'message' => 'Record reportorium wajib dipilih.'], 422);
        $row = DB::transaction(function () use ($workItem, $data) {
            $row = $workItem->links()->create($data);
            $workItem->costs()->create([
                'description' => $row->label,
                'quantity' => 1,
                'unit_price' => 0,
                'taxable' => false,
            ]);
            return $row;
        });
        $this->activity($workItem, 'link_added', "Output ditautkan: {$row->label}", null, null, $request);
        return response()->json(['status' => true, 'message' => 'Output pekerjaan ditautkan.', 'data' => $row], 201);
    }

    public function removeLink(Request $request, WorkItem $workItem, WorkItemLink $link)
    {
        abort_unless($link->work_item_id === $workItem->id, 404);
        $label = $link->label; $link->delete();
        $this->activity($workItem, 'link_removed', "Tautan dilepas tanpa menghapus data sumber: {$label}", null, null, $request);
        return response()->json(['status' => true, 'message' => 'Tautan berhasil dilepas.']);
    }

    public function addCost(Request $request, WorkItem $workItem)
    {
        $data = $request->validate(['description' => ['required', 'string', 'max:500'], 'quantity' => ['required', 'numeric', 'min:0.01'], 'unit_price' => ['required', 'integer', 'min:0'], 'taxable' => ['nullable', 'boolean']]);
        $row = $workItem->costs()->create($data);
        $this->activity($workItem, 'cost_added', "Biaya ditambahkan: {$row->description}", null, null, $request);
        return response()->json(['status' => true, 'message' => 'Komponen biaya ditambahkan.', 'data' => $row], 201);
    }

    public function createInvoice(Request $request, WorkItem $workItem)
    {
        $data = $request->validate(['invoice_type' => ['required', Rule::in(['tax', 'non_tax'])], 'discount' => ['nullable', 'integer', 'min:0']]);
        if ($workItem->status !== 'ready_to_bill') return response()->json(['status' => false, 'message' => 'Pekerjaan harus berstatus siap ditagihkan.'], 422);
        if (!$this->isAdmin($request)) return response()->json(['status' => false, 'message' => 'Penerbitan invoice hanya untuk Admin/Super Admin.'], 403);

        $invoice = DB::transaction(function () use ($workItem, $data, $request) {
            $costs = $workItem->costs()->get();
            $subtotal = (int) round($costs->sum(fn ($cost) => (float) $cost->quantity * $cost->unit_price));
            $tax = $data['invoice_type'] === 'tax' ? (int) round($costs->where('taxable', true)->sum(fn ($cost) => (float) $cost->quantity * $cost->unit_price) * 0.11) : 0;
            $discount = min((int) ($data['discount'] ?? 0), $subtotal + $tax);
            $discountPercentage = $subtotal > 0 ? round(($discount / $subtotal) * 100, 2) : 0;
            $total = $subtotal + $tax - $discount;
            $orderId = $this->nextLegacyId('orders', 'id_order', 'ORD');
            $clientName = $workItem->client_id
                ? DB::table('data_clients')->where('id_client', $workItem->client_id)->value('nama_client')
                : null;
            orders::create(['id_order' => $orderId, 'nama_pesanan' => $clientName ?: $workItem->title, 'id_user' => $workItem->assignee_id ?: optional($request->user())->id_user, 'keterangan_order' => "Workflow {$workItem->work_number}"]);
            orders::where('id_order', $orderId)->update(['status_order' => 'Selesai']);
            $detailSequence = $this->lastNumericId('detail_pesanans', 'id_detail_pesanan');
            foreach ($costs as $index => $cost) {
                detail_pesanan::create(['id_detail_pesanan' => 'DTP'.str_pad((string) ($detailSequence + $index + 1), 7, '0', STR_PAD_LEFT), 'id_order' => $orderId, 'id_pekerjaan' => (string) $workItem->id, 'jenis_pekerjaan' => $workItem->category, 'nama_pekerjaan' => $cost->description, 'no_pekerjaan' => $workItem->work_number, 'pembuat' => optional($request->user())->id_user ?: '-', 'tanggal_pekerjaan' => now()->toDateString(), 'harga' => (int) round((float) $cost->quantity * $cost->unit_price)]);
            }
            $isTax = $data['invoice_type'] === 'tax';
            $invoiceId = $this->lastNumericId($isTax ? 'invoice_taxs' : 'invoice_non_taxs', $isTax ? 'id_invoice_tax' : 'id_invoice_non_tax') + 1;
            $payload = ['id_order' => $orderId, 'status_invoice' => 'Belum Bayar', 'status_diskon' => $discount > 0, 'status_tax' => $isTax, 'tax' => $tax, 'nilai_diskon' => $discountPercentage, 'diskon' => $discount, 'grand_total' => $total];
            if ($isTax) invoice_taxs::create($payload + ['id_invoice_tax' => $invoiceId]); else invoice_non_taxs::create($payload + ['id_invoice_non_tax' => $invoiceId]);
            $displayNumber = ($isTax ? 'TAX-' : 'NT-').str_pad((string) $invoiceId, 5, '0', STR_PAD_LEFT).'/'.now()->format('Y');
            orders::where('id_order', $orderId)->update(['no_inv' => $invoiceId, 'jenis_invoice' => $isTax ? 'tax' : 'non tax']);
            $invoice = $workItem->invoices()->create(['invoice_type' => $data['invoice_type'], 'status' => 'issued', 'invoice_number' => $displayNumber, 'subtotal' => $subtotal, 'tax' => $tax, 'discount' => $discount, 'grand_total' => $total, 'issued_at' => now()]);
            $from = $workItem->status; $workItem->update(['status' => 'invoiced', 'billing_status' => 'issued', 'legacy_order_id' => $orderId]);
            $this->activity($workItem, 'invoice_issued', "Invoice {$displayNumber} diterbitkan.", $from, 'invoiced', $request);
            return $invoice;
        });
        return response()->json(['status' => true, 'message' => 'Invoice berhasil diterbitkan dan masuk modul invoice.', 'data' => $invoice]);
    }

    public function options(Request $request)
    {
        $search = trim((string) $request->query('search'));
        $like = "%{$search}%";
        $clients = DB::table('data_clients')->when($search, fn ($q) => $q->where('nama_client', 'like', $like))->orderByDesc('id_client')->limit(50)->select('id_client as id', 'nama_client as label')->get();
        $users = DB::table('users')->orderBy('nama_lengkap')->select('id_user as id', 'nama_lengkap as label', 'level_user')->get();
        return response()->json(['status' => true, 'data' => ['clients' => $clients, 'users' => $users, 'statuses' => self::STATUSES, 'transitions' => self::TRANSITIONS]]);
    }

    public function reportoriumOptions(Request $request)
    {
        $module = (string) $request->query('module_type');
        $date = (string) $request->query('date', now()->format('Y-m'));
        [$year, $month] = array_pad(explode('-', $date), 2, now()->format('m'));
        $maps = [
            'buku_akta_notaris' => ['buku_notaris', 'id_buku_notaris', 'tgl_akta', 'no_akta', 'judul_pekerjaan'],
            'buku_legalisasi' => ['buku_legalisasis', 'id_buku_legalisasi', 'tgl_surat', 'no_legalisasi', 'nama_client'],
            'buku_waarmerking' => ['buku_warmerkings', 'id_buku_warmerking', 'tgl_didaftarkan', 'no_warmerking', 'nama_client'],
            'buku_ppat' => ['buku_ppats', 'id_buku_ppat', 'tanggal_akta', 'no_akta', 'no_hak_milik'],
        ];
        if (!isset($maps[$module])) return response()->json(['status' => true, 'data' => []]);
        [$table, $id, $dateField, $number, $label] = $maps[$module];
        $rows = DB::table($table)->whereYear($dateField, $year)->whereMonth($dateField, $month)->orderByDesc($id)->limit(100)->selectRaw("{$id} as id, CONCAT(COALESCE({$number}, '-'), ' - ', COALESCE({$label}, '-')) as label")->get();
        return response()->json(['status' => true, 'data' => $rows]);
    }

    private function detail(WorkItem $item): WorkItem
    {
        $item = $item->fresh()->load(['links', 'checklists', 'activities', 'costs', 'invoices']);
        $item->setAttribute('nama_client', $item->client_id
            ? DB::table('data_clients')->where('id_client', $item->client_id)->value('nama_client')
            : null);
        $item->setAttribute('assignee_name', $item->assignee_id
            ? DB::table('users')->where('id_user', $item->assignee_id)->value('nama_lengkap')
            : null);
        return $item;
    }

    private function workRules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        return ['title' => [$required, 'string', 'max:500'], 'category' => [$required, Rule::in(['reportorium', 'custom', 'mixed'])], 'client_id' => ['nullable', 'string', 'max:15'], 'service_id' => ['nullable', 'string', 'max:15'], 'assignee_id' => ['nullable', 'string', 'max:15'], 'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])], 'due_date' => ['nullable', 'date'], 'notes' => ['nullable', 'string']];
    }

    private function nextWorkNumber(): string
    {
        $next = ((int) WorkItem::withTrashed()->lockForUpdate()->max('id')) + 1;
        return 'WRK-'.now()->format('Y').'-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    private function lastNumericId(string $table, string $field): int
    {
        return DB::table($table)
            ->lockForUpdate()
            ->pluck($field)
            ->reduce(function (int $maximum, $value) {
                $numeric = (int) preg_replace('/\D+/', '', (string) $value);
                return max($maximum, $numeric);
            }, 0);
    }

    private function nextLegacyId(string $table, string $field, string $prefix): string
    {
        return $prefix.str_pad((string) ($this->lastNumericId($table, $field) + 1), 7, '0', STR_PAD_LEFT);
    }

    private function activity(WorkItem $item, string $type, string $description, ?string $from, ?string $to, Request $request): WorkItemActivity
    {
        return $item->activities()->create(['event_type' => $type, 'actor_id' => optional($request->user())->id_user, 'from_status' => $from, 'to_status' => $to, 'description' => $description]);
    }

    private function isAdmin(Request $request): bool
    {
        return in_array(strtoupper(trim((string) optional($request->user())->level_user)), ['ADMIN', 'SUPER ADMIN', 'SUPERADMIN'], true);
    }
}
