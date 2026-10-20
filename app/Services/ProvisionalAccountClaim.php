<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;

/** Stateless adapter; ownership and replay state belong to the subscription service. */
final class ProvisionalAccountClaim
{
    private function call(string $action, array $data, string $field): bool {
        $url=config('account_claims.base_url');
        if (!is_string($url) || !str_starts_with($url,'https://')
            || !config('account_claims.username') || !config('account_claims.password')) {
            throw new \RuntimeException('Claim service is not configured');
        }
        $response=Http::baseUrl(rtrim($url,'/').'/')->withBasicAuth(
            config('account_claims.username'),config('account_claims.password'))
            ->acceptJson()->connectTimeout(5)->timeout(20)->withoutRedirecting()
            ->post('account-claims/'.$action,$data);
        if ($response->status()===403 && $action==='authorize') return false;
        $response->throw();
        if (!is_bool($response->json($field))) throw new \RuntimeException('Invalid claim service response');
        return $response->json($field);
    }
    public function ready(): bool { return $this->call('ready',[],'ready'); }
    public function isProtected(string $id): bool {
        // A forbidden response is an error, never evidence that a legacy transfer is safe.
        if (!$this->ready()) throw new \RuntimeException('Claim service unavailable');
        return $this->call('status',['provisional_user_id'=>$id],'protected');
    }
    public function issue(string $userId): string {
        $token=bin2hex(random_bytes(32));
        if (!$this->call('register',['provisional_user_id'=>$userId,'token'=>$token],'registered'))
            throw new \RuntimeException('Claim registration failed');
        return $token;
    }
    public function authorize(string $userId,string $token,string $username): bool {
        return $this->call('authorize',['provisional_user_id'=>$userId,'token'=>$token,'username'=>$username],'authorized');
    }
    public function legacyTransfer(string $id,string $target): void {
        if (!$this->call('legacy-transfer',['provisional_user_id'=>$id,'target_user_id'=>$target],'completed'))
            throw new \RuntimeException('Legacy transfer rejected');
    }
    public function transfer(string $userId,string $token,string $username,string $targetId): void {
        if (!$this->call('transfer',['provisional_user_id'=>$userId,'token'=>$token,
            'username'=>$username,'target_user_id'=>$targetId],'completed')) throw new \RuntimeException('Transfer rejected');
    }
}
