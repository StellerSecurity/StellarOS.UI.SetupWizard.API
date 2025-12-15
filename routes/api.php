<?php

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

    // Throttle all auth flows a bit to reduce brute force abuse
    Route::prefix('logincontroller')
        ->middleware('throttle:20,1')
        ->group(function () {

            // POST /api/v1/logincontroller/auth
            Route::post('/auth', [LoginController::class, 'auth'])->middleware(['wizard-client']);

            // POST /api/v1/logincontroller/create
            Route::post('/create', [LoginController::class, 'create'])->middleware(['wizard-client']);

            // POST /api/v1/logincontroller/sendresetpasswordlink
            Route::post('/sendresetpasswordlink', [LoginController::class, 'sendresetpasswordlink']);

            // POST /api/v1/logincontroller/resetpasswordupdate
            Route::post('/resetpasswordupdate', [LoginController::class, 'resetpasswordupdate']);

            // POST /api/v1/logincontroller/verifypasswordcode
            Route::post('/verifypasswordcode', [LoginController::class, 'verifypasswordcode']);
        });
});
