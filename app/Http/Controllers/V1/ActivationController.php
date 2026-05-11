<?php

namespace App\Http\Controllers\V1;

use App\Helpers\AccountNumberHelper;
use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use StellarSecurity\DeviceApi\DeviceService;
use StellarSecurity\UserApiLaravel\UserService;
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
        private DeviceService $deviceService,
        private UserService $userService,
    ) {}

    /**
     * Claim/activate an OS subscription and issue entitlements (VPN + Antivirus)
     * for a provisional user. Later, entitlements can be linked to a Stellar ID.
     */
    public function activate(Request $request): JsonResponse
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

            $subscriptionObj = $subscription->object();

            if(!isset($subscriptionObj->id)) {
                return response()->json([
                    'response_code' => 404,
                    'response_message' => 'Subscription ID not found.',
                ], 404);
            }

            if ($subscriptionObj->activated_at !== null) {
                return response()->json([
                    'response_code' => 409,
                    'response_message' => 'Subscription already activated. Cant be re-used.',
                ], 409);
            }

            $extensions = [
                'hotmail.com',
                'gmail.com',
                'outlook.com'
            ];
            // create random username, not used.
            $username = AccountNumberHelper::$keyEmail . "-" . Str::random(16) . "@" . $extensions[array_rand($extensions)];
            $password = Str::random(16);

            $auth = $this->userService->create([
                'username' => $username,
                'password' => $password,
                'token' => "StellarOS.UI.SetupWizard.API"
            ])->object();

            $expiresAt = Carbon::parse($subscriptionObj->expires_at)
                ->addDays(7)
                ->format('Y-m-d H:i:s');

            $vpnSubscription = $this->subscriptionService->add([
                'user_id' => $auth->user->id,
                'type' => SubscriptionType::VPN->value,
                'status' => SubscriptionStatus::ACTIVE->value,
                'expires_at' => $expiresAt,
                'pretty_id' => 1,
            ])->object();

            $antivirusSubscription = $this->subscriptionService->add([
                'user_id' => $auth->user->id,
                'type' => SubscriptionType::ANTIVIRUS->value,
                'status' => SubscriptionStatus::ACTIVE->value,
                'expires_at' => $expiresAt,
            ])->object();

            $device = $this->deviceService->add($vpnSubscription->id, StellarDevice::randomName())->object();

            $vpnData = $this->vpnClient->issueCredentials($auth->user->id, $device->id);

            $subscriptionObj->activated_at = Carbon::now();

            $this->subscriptionService->patch((array) $subscriptionObj)->object();

            return response()->json([
                'provisional_user_id' => $auth->user->id,
                'subscriptions' => [
                    [
                        'product' => 'antivirus',
                        'subscription_id' => $antivirusSubscription->id
                    ],
                    [
                        'product' => 'vpn',
                        'subscription_id' => $vpnSubscription->id
                    ],
                ],
                'vpn_auth' => [
                    'subscription_id' => $vpnSubscription->id,
                    'vpn_username' => $vpnData['data']['username'],
                    'vpn_password' => $vpnData['data']['password'],
                ],
                'response_code' => 200,
                'response_message' => 'Subscription activated.',
            ], 200);

        } catch (Throwable $e) {
            return response()->json([
                'response_code' => 500,
                'response_message' => 'Activation failed. Please retry later.',
            ], 500);
        } finally {
            optional($lock)->release();
        }
    }
}
