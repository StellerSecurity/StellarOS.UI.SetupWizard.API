<?php

use App\Http\Controllers\V1\ActivationController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\V1\LoginController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| This API is only responsible for StellarOS Setup Wizard auth:
| - Create Stellar ID (via central Stellar User API)
| - Login
| - Send reset password link
| - Confirm reset via confirmation code
|
*/

Route::prefix('v1')->group(function () {

    Route::prefix('activationcontroller')
        ->middleware('throttle:20,1')
        ->group(function () {
            // POST /api/v1/activationcontroller/activate
            Route::post('/activate', [ActivationController::class, 'activate']);
        });


    // Throttle all auth flows a bit to reduce brute force abuse
    Route::prefix('logincontroller')
        ->middleware('throttle:20,1')
        ->group(function () {

            // POST /api/v1/logincontroller/auth
            Route::post('/auth', [LoginController::class, 'auth'])->middleware(['wizard-client']);

            // POST /api/v1/logincontroller/create
            Route::post('/create', [\App\Http\Controllers\V1\LegacyLoginController::class, 'create'])->middleware(['wizard-client']);

            // POST /api/v1/logincontroller/sendresetpasswordlink
            Route::post('/sendresetpasswordlink', [LoginController::class, 'sendresetpasswordlink']);

            // POST /api/v1/logincontroller/resetpasswordupdate
            Route::post('/resetpasswordupdate', [LoginController::class, 'resetpasswordupdate']);

            // POST /api/v1/logincontroller/verifypasswordcode
            Route::post('/verifypasswordcode', [LoginController::class, 'verifypasswordcode']);
        });
});

Route::post('v1/activationcontroller/customer-handover', \App\Http\Controllers\V1\FactoryVpnHandoverController::class)->middleware('throttle:20,1');

// New clients explicitly opt into ownership proofs; v1 retains its released contract.
Route::prefix('v2')->middleware('throttle:20,1')->group(function () {
    Route::post('activationcontroller/activate', [ActivationController::class, 'activateWithProof']);
    Route::prefix('logincontroller')->group(function () {
        Route::post('create', [LoginController::class, 'create']);
        Route::post('auth', [LoginController::class, 'auth']);
        Route::post('sendresetpasswordlink', [LoginController::class, 'sendresetpasswordlink']);
        Route::post('resetpasswordupdate', [LoginController::class, 'resetpasswordupdate']);
        Route::post('verifypasswordcode', [LoginController::class, 'verifypasswordcode']);
    });
});
