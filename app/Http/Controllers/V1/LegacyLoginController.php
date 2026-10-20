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

        $data=$request->validate(['username'=>'required|email|max:254','password'=>'required|string|max:1024']);
        $data['token']=$this->token;
        try { $result=$this->destinationAccount($data); }
        catch (\Throwable $e) { return response()->json(['response_code'=>503],503); }
        if (!$this->upstreamSucceeded($result) || empty($result->object()?->user?->id)) {
            return response()->json(['response_code'=>400],400);
        }
        $auth=$result->object();

        if (!empty($auth?->user?->id)) {

            try {
                app(\App\Services\ProvisionalAccountClaim::class)->legacyTransfer((string)$provisional_user_id,(string)$auth->user->id);
            } catch (\Throwable $error) {
                return response()->json(['response_code'=>503,'response_message'=>'Please retry later.'],503);
            }

        }

        return response()->json($auth);
    }

}
