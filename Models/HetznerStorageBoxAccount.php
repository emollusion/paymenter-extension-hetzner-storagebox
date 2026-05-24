<?php

namespace sa6bom\HetznerStorageBox\Models;

use App\Models\Service;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Persists the mapping between a Paymenter service and a Hetzner Storage Box.
 *
 * Passwords are NEVER stored here. They are generated at provision/reset time,
 * emailed once, and immediately discarded.
 *
 * @property int         $id
 * @property int         $service_id
 * @property int         $hetzner_box_id   Hetzner's numeric Storage Box ID
 * @property string      $username         e.g. u123456
 * @property string      $hostname         e.g. u123456.your-storagebox.de
 * @property string      $box_type         e.g. bx11
 * @property string      $location         e.g. fsn1
 * @property string      $status           active | suspended | terminated
 * @property \Carbon\Carbon|null $provisioned_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class HetznerStorageBoxAccount extends Model
{
    protected $table = 'hetzner_storage_box_accounts';

    protected $fillable = [
        'service_id',
        'hetzner_box_id',
        'username',
        'hostname',
        'box_type',
        'location',
        'status',
        'provisioned_at',
    ];

    protected $casts = [
        'hetzner_box_id' => 'integer',
        'provisioned_at' => 'datetime',
    ];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
