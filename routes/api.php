<?php

use App\Http\Controllers\ContactController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProfileController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
Route::get('generate_stripe_payment/{event_profile_id}', [PaymentController::class,'generateUrlToPay']);
Route::post('webhook_connected_account', [PaymentController::class,'handle_webhookConnectedAccounts']);
Route::post('webhook_own_account', [PaymentController::class,'handle_webhookOwnAccounts']);
Route::post('/contacts', [ContactController::class, 'store']);
Route::get('get_user_stats/{event_profile_id}', [ProfileController::class,'getUserStats']);
Route::post('send_confirmation_email', [PaymentController::class,'sendConfirmationEmailFromAPI']);