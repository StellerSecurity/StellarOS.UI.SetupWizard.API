<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;

final class FactoryVpnHandover
{
    private function client() {
        $url = config('factory_handover.base_url');
        if (!str_starts_with($url, 'https://') || !config('factory_handover.username') || !config('factory_handover.password')) {
            throw new \RuntimeException('Factory handover service is not configured.');
        }
        return Http::baseUrl(rtrim($url, '/') . '/')
            ->withBasicAuth(config('factory_handover.username'), config('factory_handover.password'))
            ->acceptJson()->connectTimeout(5)->timeout(20)->withoutRedirecting();
    }

    public function register(string $osId, string $vpnId): string {
        $token = bin2hex(random_bytes(32));
        $response = $this->client()->post('factory-vpn/register', [
            'os_subscription_id' => $osId, 'vpn_subscription_id' => $vpnId, 'token' => $token,
        ])->throw();
        if ($response->json('registered') !== true) throw new \RuntimeException('Factory registration failed.');
        return $token;
    }

    public function activate(array $data) {
        return $this->client()->post('factory-vpn/activate', $data);
    }
}
