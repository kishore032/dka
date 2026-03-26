<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PublicKey extends Model
{
    /**
     * Public key store.
     * Keyed by (email_id, selector). Version increments on each update.
     */

    protected $fillable = [
        'email_id',
        'selector',
        'public_key',
        'metadata',
        'version',
    ];

    /**
     * Find a key row by email_id + selector.
     */
    public static function findKey(string $emailId, string $selector): ?self
    {
        return self::where('email_id', $emailId)
            ->where('selector', $selector)
            ->first();
    }

    /**
     * Decode the metadata JSON field into an array.
     */
    public function getMetaArray(): array
    {
        return json_decode($this->metadata ?? '{}', true) ?? [];
    }
}
