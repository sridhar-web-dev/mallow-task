<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Merchant extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug', 'currency', 'api_key_hash'];

    protected $hidden = ['api_key_hash'];

    /**
     * Generates a fresh API key, stores only its hash, and returns the plaintext once.
     * SHA-256 rather than bcrypt: the key is high-entropy random data, so a fast hash
     * is safe here and lets the lookup use a plain unique index on every request.
     */
    public function issueApiKey(): string
    {
        $key = 'mk_'.Str::random(40);

        $this->forceFill(['api_key_hash' => hash('sha256', $key)])->save();

        return $key;
    }

    public static function findByApiKey(string $key): ?self
    {
        return static::query()->where('api_key_hash', hash('sha256', $key))->first();
    }

    public function plans(): HasMany
    {
        return $this->hasMany(Plan::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
