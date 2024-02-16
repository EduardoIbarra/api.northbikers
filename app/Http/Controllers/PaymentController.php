<?php

namespace App\Http\Controllers;

use App\Http\Controllers\BaseController as BaseController;
use App\Models\Customer;
use App\Models\EventProfile;
use App\Models\Route;
use App\Models\Profile;
use Illuminate\Http\Request;
use Stripe\StripeClient;

class PaymentController extends BaseController
{
    public function generateUrlToPay($event_profile_id){
        $eventProfile = EventProfile::find($event_profile_id);
        if (!$eventProfile) {
            return response()->json([
                'success' => false,
                'message' => 'Event profile not found.',
            ], 404); // 404 Not Found status code
        }
        $route_id = $eventProfile->route_id;
        $user_id = $eventProfile->profile_id;

        $route = Route::where('id', $route_id)->first();
        $user = Profile::where('id', $user_id)->first();
        $customer = Customer::where('id', $route->customer_id)->first();

        // if($neighborhood->currency_type == "mxn"){
        if($route->amount < 150){
            return $this->sendError('NOT_MINIMUM_AMOUNT');
        }

        if($eventProfile->status == 'paid'){
            return $this->sendError('ALREADY_PAID_REFRESH');
        }

        // Define the total amount including fees
        $totalAmountIncludingFees = 1000; // This is $route->amount in your case

        // Rearrange the formula to solve for the original amount before fees
        $amountBeforeFees = $totalAmountIncludingFees / 1.036;

        // Calculate the fee based on the recalculated original amount (for demonstration)
        $calculatedFee = $amountBeforeFees * 0.036;

        // Calculate the final total to verify it matches the intended total amount including fees
        $finalTotalWithFees = $amountBeforeFees + $calculatedFee;


        $paymentData = $this->generateCheckoutInMx($finalTotalWithFees, $user->email, $calculatedFee, $route, $customer, $eventProfile);

        //guarda el id de stripe para hacer match con el webhook cuando sea pagado
        $eventProfile->stripe_checkout_id = $paymentData->id;
        $eventProfile->stripe_webhook_email_notification = $user->email;
        $eventProfile->save();

        return $this->sendResponse($paymentData, 'STRIPE_PAYMENT_LINK_GENERATED_SUCESSFULLY');

    }

    private function generateCheckoutInMx($total, $email, $fee, $route, $customer, $eventProfile){
        $stripe = new StripeClient(env('STRIPE_SECRET'));
        return $stripe->checkout->sessions->create([
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'unit_amount' => $total * 100,
                    'currency' => $customer->currency,
                    'product_data' => [ 'name' => strtoupper($route->title) ]
                ]
            ]],
            'payment_intent_data' => [
                'receipt_email' => $email,
                'application_fee_amount' => ceil(($fee) * 100),
                'transfer_data' => ['destination' => $customer->connected_stripe_account_id],
            ],
            'mode' => 'payment',
            'success_url' => 'https://gr.api.plusvalconnect.com/success_payment',
            'cancel_url' => 'https://gr.api.plusvalconnect.com/cancel_payment',
            'client_reference_id' => $eventProfile->id,
            'invoice_creation' => ['enabled' => true]
        ]);
    }
    
    public function handle_webhookConnectedAccounts(Request $request){
        \Stripe\Stripe::setApiKey(env('STRIPE_SECRET'));

        //Nos aseguramos de que la informacion venga realmente de stripe ( como un candado )
        if (isset($_GET['type']) && $_GET['type'] == "connected") {
            //este webhook solo funciona para cuentas conectadas
            $endpoint_secret = 'whsec_Dd5fZSVc3ghO2Q0yCEurw9QO4sMPxjW7';
        }else{
            //normal webhook for MX normally
            $endpoint_secret = 'whsec_auAMWLNnIFmxJkyZ113x5MGHLekt491t';
        }
        
        $payload = @file_get_contents('php://input');
        $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];
        $event = null;

        try {
            $event = \Stripe\Webhook::constructEvent(
                $payload, $sig_header, $endpoint_secret
            );
            $event_id = $event->id;

        } catch(\UnexpectedValueException $e) {
            http_response_code(400);
            exit();
        } catch(\Stripe\Exception\SignatureVerificationException $e) {
            http_response_code(400);
            exit();
        }

        $stripe_id = $event->data->object->id;
        $eventProfileId = $event->data->object->client_reference_id;

        // Handle the event
        switch ($event->type) {
            case 'checkout.session.completed':
                $this->validateExternalPayment($stripe_id, $eventProfileId, $event_id);
                break;
        }  
    }


}
