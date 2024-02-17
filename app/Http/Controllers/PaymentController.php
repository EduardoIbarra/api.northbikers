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
    public function generateUrlToPay($event_profile_id)
    {
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
        if ($route->amount < 150) {
            return $this->sendError('NOT_MINIMUM_AMOUNT');
        }

        if ($eventProfile->status == 'paid') {
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

    private function generateCheckoutInMx($total, $email, $fee, $route, $customer, $eventProfile)
    {
        $stripe = new StripeClient(env('STRIPE_SECRET'));
        return $stripe->checkout->sessions->create([
            'line_items' => [
                [
                    'quantity' => 1,
                    'price_data' => [
                        'unit_amount' => $total * 100,
                        'currency' => $customer->currency,
                        'product_data' => ['name' => strtoupper($route->title)]
                    ]
                ]
            ],
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

    public function handle_webhookConnectedAccounts(Request $request)
    {
        \Stripe\Stripe::setApiKey(env('STRIPE_SECRET'));

        // Verify the webhook signature
        $payload = $request->getContent();
        $sig_header = $request->header('Stripe-Signature');
        $endpoint_secret = env('STRIPE_WEBHOOK_SECRET');

        try {
            $event = \Stripe\Webhook::constructEvent(
                $payload,
                $sig_header,
                $endpoint_secret
            );
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }

        // Handle the event
        switch ($event->type) {
            case 'checkout.session.completed':
                $stripe_id = $event->data->object->id;
                $eventProfileId = $event->data->object->client_reference_id;
                $event_id = $event->id;

                $this->validateExternalPayment($stripe_id, $eventProfileId, $event_id);
                break;
            default:
                // Handle other types of events if needed
                break;
        }

        return response()->json(['success' => true]);
    }

    public function handle_webhookOwnAccounts(Request $request)
    {
        \Stripe\Stripe::setApiKey(env('STRIPE_SECRET'));
    
        // Retrieve the request body and signature header
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
    
        $endpointSecret = env('STRIPE_WEBHOOK_OWN_SECRET');
    
        try {
            // Construct the event
            $event = \Stripe\Webhook::constructEvent(
                $payload,
                $sigHeader,
                $endpointSecret
            );
        } catch (\UnexpectedValueException $e) {
            // Invalid payload
            return response()->json(['error' => 'Invalid payload'], 400);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            // Invalid signature
            return response()->json(['error' => 'Invalid signature'], 400);
        }
    
        // Handle the event
        switch ($event->type) {
            case 'checkout.session.completed':
                // Extract required data from the event
                $stripeId = $event->data->object->id;
                $eventProfileId = $event->data->object->client_reference_id;
                $eventId = $event->id;
    
                // Perform necessary actions based on the event
                $this->validateExternalPayment($stripeId, $eventProfileId, $eventId);
                break;
            default:
                // Handle other types of events if needed
                break;
        }
    
        // Respond with success
        return response()->json(['success' => true]);
    }

    private function validateExternalPayment($stripe_id, $income_id, $stripe_event_id)
    {
        // Log a message to the Laravel log file
        \Log::info('Validating external payment', [
            'stripe_id' => $stripe_id,
            'income_id' => $income_id,
            'stripe_event_id' => $stripe_event_id
        ]);
    }
}
