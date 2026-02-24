<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use StellarSecurity\SubscriptionLaravel\Enums\SubscriptionType;
use StellarSecurity\SubscriptionLaravel\SubscriptionService;
use StellarSecurity\UserApiLaravel\UserService;

class LoginController extends Controller
{

    private string $token = "StellarOS.UI.SetupWizard.API";

    public function __construct(public UserService $userService, public SubscriptionService $subscriptionService)
    {

    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function auth(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $response = $this->userService->auth([
            'username' => $data['username'],
            'password' => $data['password'],
            'token'    => $this->token,
        ]);

        $body = $response->object();

        // If the upstream API fails (non-2xx), we just proxy the response_code/message
        if ($response->failed()) {
            return response()->json($body, $body->response_code ?? 400);
        }

        return response()->json($body);
    }

    public function create(Request $request): JsonResponse
    {
        $provisional_user_id = $request->input('provisional_user_id');


        $data = $request->all();
        $data['token'] = $this->token;

        $auth = $this->userService->create($data)->object();

        if (!empty($auth?->user?->id) && $provisional_user_id !== null) {

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

    public function sendresetpasswordlink(Request $request): JsonResponse
    {

        $email = $request->input('email');

        if($email === null) {
            return response()->json(['response_code' => 400, 'response_message' => 'No email was provided']);
        }

        $confirmation_code = Str::password(6, false, true, false, false);
        $resetpassword = $this->userService->sendresetpasswordlink($email, $confirmation_code)->object();

        if($resetpassword->response_code !== 200) {
            return response()->json(['response_code' => $resetpassword->response_code, 'response_message' => $resetpassword->response_message]);
        }

        return response()->json(['response_code' => 200, 'response_message' => 'OK. Reset password link sent to your email.']);
    }


    public function verifypasswordcode(Request $request): JsonResponse
    {
        $email = $request->input('email');
        $confirmation_code = $request->input('confirmation_code');

        if($email === null) {
            return response()->json(['response_code' => 400, 'response_message' => 'No email was provided']);
        }

        if($confirmation_code === null) {
            return response()->json(['response_code' => 400, 'response_message' => 'No confirmation code was provided']);
        }

        $resp = $this->userService
            ->checkresetpasswordconfirmationcode($email, $confirmation_code);

        $verify = $resp->object();

        return response()->json($verify);

    }

    public function resetpasswordupdate(Request $request): JsonResponse
    {
        $email = $request->input('email');
        $confirmation_code = $request->input('confirmation_code');
        $new_password = $request->input('new_password');

        if($email === null) {
            return response()->json(['response_code' => 400, 'response_message' => 'No email was provided']);
        }

        if($new_password === null) {
            return response()->json(['response_code' => 400, 'response_message' => 'New password not was provided']);
        }

        if($confirmation_code === null) {
            return response()->json(['response_code' => 400, 'response_message' => 'No confirmation code was provided']);
        }

        $verifyandupdate = $this->userService->verifyresetpasswordconfirmationcode($email, $confirmation_code, $new_password)->object();

        return response()->json($verifyandupdate);

    }


}
