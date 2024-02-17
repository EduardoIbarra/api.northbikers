<?php

namespace App\Http\Controllers;

use App\Http\Controllers\BaseController as BaseController;
use App\Models\Customer;
use App\Models\EventProfile;
use App\Models\Route;
use App\Models\Profile;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Stripe\StripeClient;
use SendGrid\Mail\Substitution;


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
                $status = $event->data->object->payment_status;
    
                // Perform necessary actions based on the event
                $this->validateExternalPayment($stripeId, $eventProfileId, $eventId, $status);
                break;
            default:
                // Handle other types of events if needed
                break;
        }
    
        // Respond with success
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
                $status = $event->data->object->payment_status;
    
                // Perform necessary actions based on the event
                $this->validateExternalPayment($stripeId, $eventProfileId, $eventId, $status);
                break;
            default:
                // Handle other types of events if needed
                break;
        }
    
        // Respond with success
        return response()->json(['success' => true]);
    }

    private function validateExternalPayment($stripe_id, $eventProfileId, $stripe_event_id, $status)
    {
        // Log a message to the Laravel log file
        \Log::info('Validating external payment', [
            'stripe_id' => $stripe_id,
            'eventProfileId' => $eventProfileId,
            'stripe_event_id' => $stripe_event_id,
            'status' => $status
        ]);

        $eventProfile = EventProfile::find($eventProfileId);

        // Retrieve the route_id for the given event profile
        $routeId = DB::table('event_profile')
            ->where('id', $eventProfileId)
            ->value('route_id');

        // Increment participant_number for the given route_id
        $participantNumber = DB::table('event_profile')
            ->where('route_id', $routeId)
            ->max('participant_number') + 1;

        // Update the eventProfile on the database
        DB::table('event_profile')
            ->where('id', $eventProfileId)
            ->update([
                'stripe_checkout_id' => $stripe_id,
                'payment_status' => $status,
                'participant_number' => $participantNumber
            ]);

        // Prepare data for SendGrid template
        $emailData = [
            '%participant_number%' => $participantNumber
        ];

        // Send confirmation email using SendGrid template
        // Replace 'YOUR_SENDGRID_API_KEY' with your actual SendGrid API key
        // Replace 'YOUR_TEMPLATE_ID' with your SendGrid template ID
        $email = new \SendGrid\Mail\Mail();
        $email->setFrom("multitut.programacion@gmail.com", "North Bikers");
        $email->setTemplateId("d-070db3211c604dd79dcd7b726dc10be1"); // Set SendGrid template ID
        $email->addTo($eventProfile->stripe_webhook_email_notification, $eventProfile->full_name);

        \Log::info('Email to: ' . $eventProfile->stripe_webhook_email_notification);

        // Add dynamic template data
        foreach ($emailData as $key => $value) {
            $email->addDynamicTemplateData($key, $value);
        }

        $sendgrid = new \SendGrid(getenv('SENDGRID_API_KEY'));

        try {
            $response = $sendgrid->send($email);
            \Log::info('Email sent successfully.');
        } catch (Exception $e) {
            \Log::error('Failed to send email: ' . $e->getMessage());
        }
    }


}
