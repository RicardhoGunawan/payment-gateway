<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessPaymentNotification;
use App\Models\PaymentNotification;
use App\Services\MidtransService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MidtransWebhookController extends Controller
{
    public function __construct(
        private MidtransService $midtransService
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $payload = $request->all();

        \Log::info('Midtrans Webhook Received', $payload);

        // Verify signature
        $orderId = $payload['order_id'] ?? '';
        $statusCode = $payload['status_code'] ?? '';
        $grossAmount = $payload['gross_amount'] ?? '';
        $signatureKey = $payload['signature_key'] ?? '';

        if (!$this->midtransService->verifySignature($orderId, $statusCode, $grossAmount, $signatureKey)) {
            \Log::warning('Invalid Midtrans signature', ['order_id' => $orderId]);
            
            return response()->json([
                'success' => false,
                'message' => 'Invalid signature',
            ], 403);
        }

        // Check for duplicate notification (idempotency)
        $existingNotification = PaymentNotification::where('payload->order_id', $orderId)
            ->where('payload->transaction_status', $payload['transaction_status'] ?? '')
            ->where('status', 'processed')
            ->first();

        if ($existingNotification) {
            \Log::info('Duplicate notification ignored', ['order_id' => $orderId]);
            
            return response()->json([
                'success' => true,
                'message' => 'Notification already processed',
            ]);
        }

        // Store notification
        $notification = PaymentNotification::create([
            'payload' => $payload,
            'received_at' => now(),
            'status' => 'pending',
        ]);

        // Dispatch job to process notification
        ProcessPaymentNotification::dispatch($notification);

        return response()->json([
            'success' => true,
            'message' => 'Notification received',
        ]);
    }
}
