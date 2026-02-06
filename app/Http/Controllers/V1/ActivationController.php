<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Support\Facades\Request;
use StellarSecurity\DeviceApi\Facades\StellarDevice;
use StellarSecurity\LaravelVpn\Services\VpnServerClient;
use StellarSecurity\SubscriptionLaravel\Enums\SubscriptionStatus;
use StellarSecurity\SubscriptionLaravel\Enums\SubscriptionType;
use StellarSecurity\SubscriptionLaravel\SubscriptionService;

class ActivationController extends Controller
{

    public function __construct(
        private SubscriptionService $subscriptionService,
        private VpnServerClient $vpnClient)
    {

    }

    /**
     * The initial window on setupWizard.
     * If the OS-subscription ID is verified & validated, then create the necessary IDS.
     * @return \Illuminate\Http\JsonResponse
     */
    public function activate(Request $request) {
        $subscription_id = $request->input('subscription_id');

        if(empty($subscription_id)) {
            return response()->json(['response_code' => 400, 'response_message' => 'No Subscription ID was provided.']);
        }

        $subscription = $this->subscriptionService->find($subscription_id, 6);

        if($subscription === null) {
            return response()->json(['response_code' => 400, 'response_message' => 'Wrong subscription ID.']);
        }

        $subscription = $subscription->object();

        if($subscription->activated_at !== null) {
            return response()->json(['response_code' => 400, 'response_message' => 'Subscription already activated. Cant be re-used.']);
        }

        $subscription->activated_at = Carbon::now();

        $temp_user_id = time();

        // OS was OK and patched.
        $this->subscriptionService->patch((array) $subscription)->object();

        $expires_at = Carbon::now()->addDays(368);

        // lets create VPN license.
        $vpnSubscription = $this->subscriptionService->add([
            'user_id' => $temp_user_id,
            'type' => SubscriptionType::VPN->value,
            'status' => SubscriptionStatus::ACTIVE->value,
            'expires_at' => $expires_at,
            'pretty_id'  => 1
        ])->object();

        // lets create Antivirus license.
        $antivirusSubscription = $this->subscriptionService->add([
            'user_id' => $temp_user_id,
            'type' => SubscriptionType::ANTIVIRUS->value,
            'status' => SubscriptionStatus::ACTIVE->value,
            'expires_at' => $expires_at
        ])->object();

        $device = StellarDevice::add($vpnSubscription->id, StellarDevice::randomName(), true);
        $vpnData = $this->vpnClient->issueCredentials($vpnSubscription->id, $device->id);

        return response()->json([
            'antivirus' => [
                'subscription_id' => $antivirusSubscription->id,
            ],
            'vpn_auth' => [
                'subscription_id' => $vpnSubscription->id,
                'vpn_username' => $vpnData['username'],
                'vpn_password' => $vpnData['password'],
            ],
            'response_code' => 200,
            'response_message' => 'Subscription activated.',
        ]);

    }

}
