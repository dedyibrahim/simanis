<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo; // <-- Pastikan ini ditambahkan
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Event extends Model
{
    use HasFactory;

    /**
     * Atribut yang dapat diisi secara massal.
     * TAMBAHKAN 'creator_id' DI SINI
     */
    protected $fillable = [
        'title',
        'description',
        'location',
        'start_datetime',
        'end_datetime',
        'color',
        'creator_id', // <-- DITAMBAHKAN
        'google_event_id',
        'google_synced_at',
        'google_sync_error',
    ];

    protected $casts = [
        'start_datetime' => 'datetime',
        'end_datetime' => 'datetime',
        'google_synced_at' => 'datetime',
    ];

    /**
     * Relasi many-to-many ke User (sebagai peserta).
     */
    public function users(): BelongsToMany {
        return $this->belongsToMany(User::class);
    }

    /**
     * BARU: Relasi one-to-many (inverse) ke User (sebagai pembuat).
     * Method ini mendefinisikan bahwa sebuah 'Event' "belongs to" (milik) satu 'User'.
     */
    public function creator(): BelongsTo {
        // Relasi ini terhubung ke model User melalui foreign key 'creator_id'
        return $this->belongsTo(User::class, 'creator_id');
    }
}
