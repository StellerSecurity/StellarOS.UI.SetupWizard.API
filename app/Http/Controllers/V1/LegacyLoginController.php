<?php
namespace App\Http\Controllers\V1;

use App\Helpers\AccountNumberHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use StellarSecurity\SubscriptionLaravel\Enums\SubscriptionType;

/** Compatibility for released clients only; new activations use v2 ownership proofs. */
class LegacyLoginController extends LoginController
{
    private string $token = "StellarOS.UI.SetupWizard.API";
    public function create(Request $request): JsonResponse
    {
        $provisional_user_id = $request->input('provisional_user_id');

        if(empty($provisional_user_id)){
            return response()->json(['response_code' => 400]);
        }

        // A proof-protected activation must never be transferable through legacy v1.
        // Retain consumed proof rows so completion cannot re-enable a downgrade.
        try {
            if (app(\App\Services\ProvisionalAccountClaim::class)->isProtected((string) $provisional_user_id)) {
                return response()->json(['response_code' => 403, 'response_message' => 'Activation proof required.'], 403);
            }
        } catch (\Throwable $error) {
            return response()->json(['response_code' => 503, 'response_message' => 'Please retry later.'], 503);
        }
        $user = $this->userService->user($provisional_user_id)->object();

        if(!isset($user->user->id)) {
            return response()->json(['response_code' => 400]);
        }

        if(!str_contains($user->user->email, AccountNumberHelper::$keyEmail)) {
            return response()->json(['response_code' => 400]);
        }

        $data = $request->all();
        $data['token'] = $this->token;

        $auth = $this->userService->create($data)->object();

        if (!empty($auth?->user?->id)) {

            // VPN subscriptions
            $vpnSubscriptions = $this->subscriptionService
                ->findUserSubscriptions($provisional_user_id, SubscriptionType::VPN->value)
                ->object();

            foreach ($vpnSubscriptions as $vpnSubscription) {
                $vpnSubscription->user_id = $auth->user->id;
                $this->subscriptionService->patch((array) $vpnSubscription);
            }

            // Antivirus subscriptions
            $antivirusSubscriptions = $this->subscriptionService
                ->findUserSubscriptions($provisional_user_id, SubscriptionType::ANTIVIRUS->value)
                ->object();

            foreach ($antivirusSubscriptions as $antivirusSubscription) {
                $antivirusSubscription->user_id = $auth->user->id;
                $this->subscriptionService->patch((array) $antivirusSubscription);
            }
        }

        return response()->json($auth);
    }

}
