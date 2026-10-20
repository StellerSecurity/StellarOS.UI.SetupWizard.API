<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;

/** A public application identifier is never authorization to transfer entitlements. */
final class ProvisionalAccountClaim
{
    public function issue(string $userId): string
    {
        $token = bin2hex(random_bytes(32));
        DB::table('provisional_account_claims')->insert([
            'provisional_user_id' => $userId,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addYear(),
        ]);
        return $token;
    }

    public function authorize(string $userId, string $token, string $username): bool
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/', $token)) return false;
        return DB::transaction(function () use ($userId, $token, $username) {
            $row = DB::table('provisional_account_claims')->where('provisional_user_id', $userId)->lockForUpdate()->first();
            if (!$row || $row->consumed_at !== null || now()->greaterThanOrEqualTo($row->expires_at)
                || !hash_equals($row->token_hash, hash('sha256', $token))) return false;
            if ($row->target_username !== null && $row->target_username !== $username) return false;
            // Persist the destination before contacting other services. A partial failure
            // can be retried, but must never redirect the remaining entitlements.
            DB::table('provisional_account_claims')->where('provisional_user_id', $userId)
                ->update(['target_username' => $username]);
            return true;
        });
    }

    public function bindTarget(string $userId, string $targetId): bool
    {
        return DB::transaction(function () use ($userId, $targetId) {
            $row = DB::table('provisional_account_claims')->where('provisional_user_id', $userId)->lockForUpdate()->first();
            if (!$row || $row->consumed_at !== null || $row->target_username === null) return false;
            if ($row->target_user_id !== null && $row->target_user_id !== $targetId) return false;
            DB::table('provisional_account_claims')->where('provisional_user_id', $userId)->update(['target_user_id' => $targetId]);
            return true;
        });
    }

    public function complete(string $userId): void
    {
        DB::table('provisional_account_claims')->where('provisional_user_id', $userId)
            ->whereNotNull('target_user_id')->update(['consumed_at' => now()]);
    }
}
