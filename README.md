# StellarOS Setup Wizard API
Secure user onboarding for StellarOS devices.

The StellarOS Setup Wizard API is the backend service responsible for handling account creation, authentication, and password recovery during the initial setup of StellarOS.  
It integrates directly with the **Stellar User Service** (`stellarsecurity-user-laravel`) and provides a clean interface for the Setup Wizard UI.

All communication is fully API-based and designed for privacy-first devices running StellarOS.

---

## 🚀 Features

- **User Login** (email + password)
- **User Account Creation**
- **Password Reset: Request + Verification**
- **Secure token-based authentication**
- Built on **Laravel 12**
- Uses official **StellarSecurity User API package**
- Fully ready for deployment on **Azure App Service**

---

## 📦 Installation

Clone the repository and install dependencies:

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Install the Stellar User API package:

```bash
composer require stellar-security/stellarsecurity-user-laravel
```

---

## ⚙️ Configuration

Set the following environment variables:

```
STELLAR_USER_API_BASE_URL=https://api.stellarsecurity.com
STELLAR_USER_API_KEY=your-key-here

APP_URL=https://your-wizard-api-url.com
APP_ENV=production
```

---

## 🔐 Trust Proxies (Azure Required)

For Laravel 12 on Azure App Service, edit `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->trustProxies(
        at: '*',
        headers: \Illuminate\Http\Request::HEADER_X_FORWARDED_ALL,
    );
})
```

---

## 📡 API Endpoints

### **POST /api/v1/auth**
Authenticate a user.

Request:
```json
{
  "username": "email@example.com",
  "password": "strongpassword"
}
```

---

### **POST /api/v1/create**
Create a new user account.

Request:
```json
{
  "username": "email@example.com",
  "password": "mypassword"
}
```

---

### **POST /api/v1/sendresetpasswordlink**
Send a 6-digit password reset code to the user's email.

Request:
```json
{ "email": "email@example.com" }
```

---

### **POST /api/v1/resetpasswordupdate**
Verify the code and update the user’s password.

Request:
```json
{
  "email": "email@example.com",
  "confirmation_code": "123456",
  "new_password": "newPassword123"
}
```

---

## 🧩 Routes

Add this to `routes/api.php`:

```php
use App\Http\Controllers\V1\LoginController;

Route::prefix('v1')->group(function () {
    Route::post('auth', [LoginController::class, 'auth']);
    Route::post('create', [LoginController::class, 'create']);
    Route::post('sendresetpasswordlink', [LoginController::class, 'sendresetpasswordlink']);
    Route::post('resetpasswordupdate', [LoginController::class, 'resetpasswordupdate']);
});
```

---

## 🏛 Architecture

The API uses the following flow:

```
StellarOS Device → Setup Wizard UI → StellarOS Wizard API → 
Stellar User Service → Token Issued → Device Setup Completed
```

---

## 🛡 Security

- All tokens are issued through Stellar’s official User Service
- No passwords are ever stored locally
- Fully stateless authentication
- Designed for secure, privacy-first operating systems  
