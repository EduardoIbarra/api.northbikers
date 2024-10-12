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
        $coupon_code = request()->query('coupon_code');
        $eventProfile = EventProfile::find($event_profile_id);
        if (!$eventProfile) {
            return response()->json([
                'success' => false,
                'message' => 'Event profile not found.',
            ], 404);
        }

        // Check if the payment status is 'paid'
        if ($eventProfile->payment_status == 'paid') {
            return ['status' => 'error', 'message' => 'Su pago ya fue hecho anteriormente, por favor verifica tu correo con la confirmación.'];
        }

        $route_id = $eventProfile->route_id;
        $user_id = $eventProfile->profile_id;

        $route = Route::where('id', $route_id)->first();
        $user = Profile::where('id', $user_id)->first();
        $customer = Customer::where('id', $route->customer_id)->first();

        // Minimum amount check
        if ($route->amount < 150) {
            return $this->sendError('NOT_MINIMUM_AMOUNT');
        }

        // Coupon logic
        $discount = 0;
        if ($coupon_code) {
            $coupon = Coupon::where('code', $coupon_code)->first();
            if ($coupon) {
                if ($coupon->current_uses < 4 && $coupon->expires_at > now()) {
                    $discount = $coupon->discount_percentage;
                    // Increment the coupon usage
                    $coupon->current_uses += 1;
                    $coupon->save();
                } else {
                    return $this->sendError('COUPON_EXPIRED_OR_MAXIMUM_USES_REACHED');
                }
            } else {
                return $this->sendError('INVALID_COUPON_CODE');
            }
        }

        // Determine the total payment amount
        if ($eventProfile->is_team) {
            $totalAmountIncludingFees = $route->team_price;
        } elseif ($eventProfile->is_couple) {
            $totalAmountIncludingFees = $route->couple_price;
        } else {
            $totalAmountIncludingFees = $route->amount;
        }

        // Apply discount
        if ($discount > 0) {
            $totalAmountIncludingFees *= (1 - ($discount / 100));
        }

        // Calculate fees and generate payment data
        $stripeFeePercentage = 0.036;
        $stripeFixedFee = 3;
        $appFeePercentage = 0.036;

        $amountBeforeFees = ($totalAmountIncludingFees - $stripeFixedFee) / (1 + $stripeFeePercentage + $appFeePercentage);
        $stripeFee = $amountBeforeFees * $stripeFeePercentage + $stripeFixedFee;
        $appFee = $amountBeforeFees * $appFeePercentage;
        $merchantAmount = $amountBeforeFees - $appFee;

        $paymentData = $this->generateCheckoutInMx($totalAmountIncludingFees * 100, $user->email, ($stripeFee + $appFee) * 100, $route, $customer, $eventProfile);

        $eventProfile->stripe_checkout_id = $paymentData->id;
        $eventProfile->stripe_webhook_email_notification = $user->email;
        $eventProfile->coupon_code = $coupon_code; // Save the coupon used
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
                        'unit_amount' => $total,
                        'currency' => $customer->currency,
                        'product_data' => ['name' => strtoupper($route->title)]
                    ]
                ]
            ],
            'payment_intent_data' => [
                'receipt_email' => $email,
                // 'application_fee_amount' => ceil($fee),
                // 'transfer_data' => ['destination' => $customer->connected_stripe_account_id],
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
                $paymentIntentId = $event->data->object->payment_intent;
                // Perform necessary actions based on the event
                $this->validateExternalPayment($stripeId, $eventProfileId, $eventId, $status, $paymentIntentId);
                break;
            case 'checkout.session.async_payment_succeeded':
                // Extract required data from the event
                $stripeId = $event->data->object->id;
                $eventProfileId = $event->data->object->client_reference_id;
                $eventId = $event->id;
                $status = $event->data->object->payment_status;
                $paymentIntentId = $event->data->object->payment_intent;
                // Perform necessary actions based on the event
                $this->validateExternalPayment($stripeId, $eventProfileId, $eventId, $status, $paymentIntentId);
                break;
            case 'charge.refunded':
                // Extract required data from the event
                $stripeId = $event->data->object->id;
                $paymentIntent = $event->data->object->payment_intent;
                $status = $event->data->object->payment_status;
                $isRefunded = $event->data->object->refunded;

                if ($isRefunded) {
                    // Log the refunded event details
                    \Log::info('refund.refunded', [
                        'stripeId' => $stripeId,
                        'paymentIntent' => $paymentIntent,
                        'status' => $status
                    ]);

                    // Update the event_profile table with payment_status and set participant_number to null
                    \DB::table('event_profile')
                        ->where('payment_intent', $paymentIntent)
                        ->update([
                            'payment_status' => 'refunded',
                            'participant_number' => null
                        ]);
                } else {
                    // Log that the refunded property was not set to true
                    \Log::info('Refunded property was not set to true for event ID: ' . $event->id);
                }

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
                $paymentIntentId = $event->data->object->payment_intent;
                // Perform necessary actions based on the event
                $this->validateExternalPayment($stripeId, $eventProfileId, $eventId, $status, $paymentIntentId);
                break;
            case 'checkout.session.async_payment_succeeded':
                // Extract required data from the event
                $stripeId = $event->data->object->id;
                $eventProfileId = $event->data->object->client_reference_id;
                $eventId = $event->id;
                $status = $event->data->object->payment_status;
                $paymentIntentId = $event->data->object->payment_intent;
                // Perform necessary actions based on the event
                $this->validateExternalPayment($stripeId, $eventProfileId, $eventId, $status, $paymentIntentId);
                break;
            case 'charge.refunded':
                // Extract required data from the event
                $stripeId = $event->data->object->id;
                $paymentIntent = $event->data->object->payment_intent;
                $status = $event->data->object->payment_status;
                $isRefunded = $event->data->object->refunded;

                if ($isRefunded) {
                    // Log the refunded event details
                    \Log::info('refund.refunded', [
                        'stripeId' => $stripeId,
                        'paymentIntent' => $paymentIntent,
                        'status' => $status
                    ]);

                    // Update the event_profile table with payment_status and set participant_number to null
                    \DB::table('event_profile')
                        ->where('payment_intent', $paymentIntent)
                        ->update([
                            'payment_status' => 'refunded',
                            'participant_number' => null
                        ]);
                } else {
                    // Log that the refunded property was not set to true
                    \Log::info('Refunded property was not set to true for event ID: ' . $event->id);
                }

                break;
            default:
                // Handle other types of events if needed
                break;
        }

        // Respond with success
        return response()->json(['success' => true]);
    }

    private function validateExternalPayment($stripe_id, $eventProfileId, $stripe_event_id, $status, $paymentIntentId)
    {
        // Log a message to the Laravel log file
        \Log::info('Validating external payment', [
            'stripe_id' => $stripe_id,
            'eventProfileId' => $eventProfileId,
            'stripe_event_id' => $stripe_event_id,
            'status' => $status,
            'paymentIntentId' => $paymentIntentId,
        ]);

        $eventProfile = EventProfile::find($eventProfileId);

        // Retrieve the route_id for the given event profile
        $routeId = DB::table('event_profile')
            ->where('id', $eventProfileId)
            ->value('route_id');

        if ($status == 'unpaid') {
            // Update the eventProfile on the database
            DB::table('event_profile')
                ->where('id', $eventProfileId)
                ->update([
                    'stripe_checkout_id' => $stripe_id,
                    'payment_status' => $status,
                    'payment_intent' => $paymentIntentId,
                ]);
            return;
        }

        // Increment participant_number for the given route_id
        $participantNumber = DB::table('event_profile')
            ->where('route_id', $routeId)
            ->max('participant_number') + 1;

        \Log::info('Participant number: ' . $participantNumber);

        // Update the eventProfile on the database
        DB::table('event_profile')
            ->where('id', $eventProfileId)
            ->update([
                'stripe_checkout_id' => $stripe_id,
                'payment_status' => $status,
                'participant_number' => $participantNumber,
                'payment_intent' => $paymentIntentId,
            ]);

        $participantNumberPadded = str_pad($participantNumber, 3, "0", STR_PAD_LEFT);

        // Prepare data for email
        $emailData = [
            'participant_number' => $participantNumberPadded,
            'event_profile_id' => $eventProfileId,
        ];

        // Send email
        $this->sendConfirmationEmail($eventProfile, $emailData);
    }

    public function sendConfirmationEmailFromAPI(Request $request)
    {
        // Ensure the request contains the eventProfileId
        if (!$request->has('eventProfileId')) {
            return response()->json(['error' => 'eventProfileId is required'], 400);
        }

        $eventProfileId = $request->input('eventProfileId');

        // Find the EventProfile by the provided ID
        $eventProfile = EventProfile::find($eventProfileId);

        // Ensure the EventProfile exists
        if (!$eventProfile) {
            return response()->json(['error' => 'EventProfile not found'], 404);
        }

        // Find the associated Route using the route_id from the EventProfile
        $route = Route::find($eventProfile->route_id);

        // Ensure the Route exists
        if (!$route) {
            return response()->json(['error' => 'Route not found'], 404);
        }

        // Prepare data for the email
        $emailData = [
            'participant_number' => str_pad($eventProfile->participant_number, 3, "0", STR_PAD_LEFT),
            'route_title' => $route->title,
            'event_profile_id' => $eventProfileId,
        ];

        // Send the email using the sendConfirmationEmail method
        try {
            $this->sendConfirmationEmail($eventProfile, $emailData);
            return response()->json(['message' => 'Email sent successfully'], 200);
        } catch (\Exception $e) {
            \Log::error('Failed to send confirmation email: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to send confirmation email'], 500);
        }
    }


    private function sendConfirmationEmail($eventProfile, $emailData)
    {
        // Send confirmation email using SendGrid template
        $email = new \SendGrid\Mail\Mail();
        $email->setFrom("advmx.lam@gmail.com", "Joaquín Lam de ADV NL");
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
