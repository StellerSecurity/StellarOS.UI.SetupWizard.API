<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use StellarSecurity\DeviceApi\DeviceService;
use Throwable;
use StellarSecurity\DeviceApi\Facades\StellarDevice;
use StellarSecurity\LaravelVpn\Services\VpnServerClient;
use StellarSecurity\SubscriptionLaravel\Enums\SubscriptionStatus;
use StellarSecurity\SubscriptionLaravel\Enums\SubscriptionType;
use StellarSecurity\SubscriptionLaravel\SubscriptionService;

class ActivationController extends Controller
{
    public function __construct(
        private SubscriptionService $subscriptionService,
        private VpnServerClient $vpnClient,
        private DeviceService $deviceService
    ) {}

    /**
     * Claim/activate an OS subscription and issue entitlements (VPN + Antivirus)
     * for a provisional user. Later, entitlements can be linked to a Stellar ID.
     */
    public function activate(Request $request)
    {
        $subscriptionId = (string) $request->input('subscription_id');

        if ($subscriptionId === '') {
            return response()->json([
                'response_code' => 422,
                'response_message' => 'No Subscription ID was provided.',
            ], 422);
        }

        // Distributed lock to prevent double activation in concurrent requests.
        $lock = Cache::lock("activation:{$subscriptionId}", 15);

        if (! $lock->get()) {
            return response()->json([
                'response_code' => 409,
                'response_message' => 'Activation already in progress. Please retry.',
            ], 409);
        }

        try {
            $subscription = $this->subscriptionService->find($subscriptionId, 6);

            if ($subscription === null) {
                return response()->json([
                    'response_code' => 404,
                    'response_message' => 'Wrong subscription ID.',
                ], 404);
            }

            $subscriptionObj = $subscription->object();

            if ($subscriptionObj->activated_at !== null) {
                return response()->json([
                    'response_code' => 409,
                    'response_message' => 'Subscription already activated. Cant be re-used.',
                ], 409);
            }

            // stored on the phone.
            // @TODO: Do a better solution in future.
            $provisionalUserId = '';
            for ($i = 0; $i < 12; $i++) {
                $provisionalUserId .= random_int(0, 9);
            }

            $expiresAt = Carbon::now()->addDays(368);

            $vpnSubscription = $this->subscriptionService->add([
                'user_id' => $provisionalUserId,
                'type' => SubscriptionType::VPN->value,
                'status' => SubscriptionStatus::ACTIVE->value,
                'expires_at' => $expiresAt,
                'pretty_id' => 1,
            ])->object();

            $antivirusSubscription = $this->subscriptionService->add([
                'user_id' => $provisionalUserId,
                'type' => SubscriptionType::ANTIVIRUS->value,
                'status' => SubscriptionStatus::ACTIVE->value,
                'expires_at' => $expiresAt,
            ])->object();


            $device = $this->deviceService->add($subscriptionId, StellarDevice::randomName())->object();

            $vpnData = $this->vpnClient->issueCredentials($vpnSubscription->id, $device->id);

            $subscriptionObj->activated_at = Carbon::now();

            $this->subscriptionService->patch((array) $subscriptionObj)->object();

            return response()->json([
                'provisional_user_id' => $provisionalUserId,
                'entitlements' => [
                    [
                        'product' => 'antivirus',
                        'subscription_id' => $antivirusSubscription->id,
                        'expires_at' => $expiresAt->toIso8601String(),
                    ],
                    [
                        'product' => 'vpn',
                        'subscription_id' => $vpnSubscription->id,
                        'expires_at' => $expiresAt->toIso8601String(),
                        'device_id' => $device->id,
                    ],
                ],
                'vpn_auth' => [
                    'subscription_id' => $vpnSubscription->id,
                    'vpn_username' => $vpnData['username'],
                    'vpn_password' => $vpnData['password'],
                ],
                'response_code' => 200,
                'response_message' => 'Subscription activated.',
            ], 200);

        } catch (Throwable $e) {
            return response()->json([
                'response_code' => 500,
                'response_message' => 'Activation failed. Please retry later.',
            ], 500);
        }
    }
}
