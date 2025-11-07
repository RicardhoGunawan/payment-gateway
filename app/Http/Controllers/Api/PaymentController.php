<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Services\MidtransService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(
        private MidtransService $midtransService
    ) {
    }

    /**
     * Create payment with Snap (existing method - supports multiple payment methods)
     */
    public function createPayment(Order $order): JsonResponse
    {
        // Check ownership
        if ($order->user_id !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        // Check if payment already exists
        if ($order->payment && $order->payment->status === 'success') {
            return response()->json([
                'success' => false,
                'message' => 'Order already paid',
            ], 422);
        }

        $order->load('orderItems.product', 'user');

        // Prepare Midtrans transaction details
        $midtransOrderId = 'ORDER-' . $order->id . '-' . time();

        $itemDetails = $order->orderItems->map(function ($item) {
            return [
                'id' => $item->product_id,
                'price' => (int) $item->unit_price,
                'quantity' => $item->quantity,
                'name' => $item->product->name,
            ];
        })->toArray();

        // Add shipping as item if exists
        if ($order->shipping_amount > 0) {
            $itemDetails[] = [
                'id' => 'SHIPPING',
                'price' => (int) $order->shipping_amount,
                'quantity' => 1,
                'name' => 'Shipping Cost',
            ];
        }

        $params = [
            'transaction_details' => [
                'order_id' => $midtransOrderId,
                'gross_amount' => (int) $order->total_amount,
            ],
            'item_details' => $itemDetails,
            'customer_details' => array_filter([
                'first_name' => $order->user->name ?? null,
                'email' => $order->user->email ?? null,
                'phone' => $order->user->phone ?? null,
                'billing_address' => array_filter([
                    'first_name' => $order->user->name ?? null,
                    'address' => $order->user->address ?? null,
                    'city' => $order->user->city ?? null,
                    'postal_code' => $order->user->postal_code ?? null,
                    'phone' => $order->user->phone ?? null,
                    'country_code' => 'IDN',
                ]),
                'shipping_address' => array_filter([
                    'first_name' => $order->shipping_name ?? $order->user->name ?? null,
                    'address' => $order->shipping_address ?? $order->user->address ?? null,
                    'city' => $order->shipping_city ?? null,
                    'postal_code' => $order->shipping_postal_code ?? null,
                    'phone' => $order->shipping_phone ?? $order->user->phone ?? null,
                    'country_code' => 'IDN',
                ]),
            ])
        ];

        $result = $this->midtransService->createSnapTransaction($params);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create payment',
                'error' => $result['message'] ?? null,
            ], 500);
        }

        // Save payment record
        $payment = Payment::create([
            'order_id' => $order->id,
            'payment_method' => 'midtrans_snap',
            'midtrans_order_id' => $midtransOrderId,
            'snap_token' => $result['snap_token'],
            'amount' => $order->total_amount,
            'status' => 'pending',
            'payment_url' => $result['redirect_url'],
            'raw_response' => $result,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Payment created successfully',
            'data' => [
                'payment_id' => $payment->id,
                'snap_token' => $result['snap_token'],
                'redirect_url' => $result['redirect_url'],
            ],
        ], 201);
    }

    /**
     * Create QRIS payment (GoPay - Core API)
     */
    public function createQrisPayment(Order $order, Request $request): JsonResponse
    {
        // Check ownership
        if ($order->user_id !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        // Check if payment already exists
        if ($order->payment && $order->payment->status === 'success') {
            return response()->json([
                'success' => false,
                'message' => 'Order already paid',
            ], 422);
        }

        $order->load('orderItems.product', 'user');

        // Prepare Midtrans transaction details
        $midtransOrderId = 'QRIS-' . $order->id . '-' . time();

        $itemDetails = $order->orderItems->map(function ($item) {
            return [
                'id' => $item->product_id,
                'price' => (int) $item->unit_price,
                'quantity' => $item->quantity,
                'name' => $item->product->name,
            ];
        })->toArray();

        // Add shipping as item if exists
        if ($order->shipping_amount > 0) {
            $itemDetails[] = [
                'id' => 'SHIPPING',
                'price' => (int) $order->shipping_amount,
                'quantity' => 1,
                'name' => 'Shipping Cost',
            ];
        }

        $params = [
            'transaction_details' => [
                'order_id' => $midtransOrderId,
                'gross_amount' => (int) $order->total_amount,
            ],
            'item_details' => $itemDetails,
            'customer_details' => array_filter([
                'first_name' => $order->user->name ?? null,
                'email' => $order->user->email ?? null,
                'phone' => $order->user->phone ?? null,
                'billing_address' => array_filter([
                    'first_name' => $order->user->name ?? null,
                    'address' => $order->user->address ?? null,
                    'city' => $order->user->city ?? null,
                    'postal_code' => $order->user->postal_code ?? null,
                    'phone' => $order->user->phone ?? null,
                    'country_code' => 'IDN',
                ]),
                'shipping_address' => array_filter([
                    'first_name' => $order->shipping_name ?? $order->user->name ?? null,
                    'address' => $order->shipping_address ?? $order->user->address ?? null,
                    'city' => $order->shipping_city ?? null,
                    'postal_code' => $order->shipping_postal_code ?? null,
                    'phone' => $order->shipping_phone ?? $order->user->phone ?? null,
                    'country_code' => 'IDN',
                ]),
            ])
        ];

        // Add callback URL for mobile deeplink (optional)
        $callbackUrl = $request->input('callback_url');
        if ($callbackUrl) {
            $params['gopay'] = [
                'enable_callback' => true,
                'callback_url' => $callbackUrl,
            ];
        }

        $result = $this->midtransService->createQrisTransaction($params);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create QRIS payment',
                'error' => $result['message'] ?? null,
            ], 500);
        }

        // Save payment record
        $payment = Payment::create([
            'order_id' => $order->id,
            'payment_method' => 'qris',
            'midtrans_order_id' => $midtransOrderId,
            'midtrans_transaction_id' => $result['transaction_id'],
            'amount' => $order->total_amount,
            'status' => 'pending',
            'qr_code_url' => $result['qr_code_url'],
            'deeplink_url' => $result['deeplink_url'],
            'raw_response' => $result['raw_response'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'QRIS payment created successfully',
            'data' => [
                'payment_id' => $payment->id,
                'order_id' => $result['order_id'],
                'transaction_id' => $result['transaction_id'],
                'transaction_status' => $result['transaction_status'],
                'qr_code_url' => $result['qr_code_url'],
                'deeplink_url' => $result['deeplink_url'],
                'actions' => $result['actions'],
            ],
        ], 201);
    }

    public function cancelPayment(Payment $payment): JsonResponse
    {
        // Check ownership
        if ($payment->order->user_id !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        if ($payment->status === 'success') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot cancel successful payment',
            ], 422);
        }

        // Try to cancel on Midtrans
        $result = $this->midtransService->cancelTransaction($payment->midtrans_order_id);

        // Update payment status
        $payment->update([
            'status' => 'cancel',
        ]);

        // Update order status
        $payment->order->update([
            'status' => 'cancelled',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Payment cancelled successfully',
            'data' => $payment,
        ]);
    }

    public function checkStatus(Payment $payment): JsonResponse
    {
        // Check ownership
        if ($payment->order->user_id !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $result = $this->midtransService->checkStatus($payment->midtrans_order_id);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to check payment status',
            ], 500);
        }

        $data = $result['data'];
        $status = $this->midtransService->mapStatus(
            $data['transaction_status'],
            $data['fraud_status'] ?? null
        );

        // Update payment if status changed
        if ($payment->status !== $status) {
            $payment->update([
                'status' => $status,
                'midtrans_transaction_id' => $data['transaction_id'] ?? null,
            ]);

            // Update order status
            if ($status === 'success') {
                $payment->order->update(['status' => 'paid']);
            } elseif (in_array($status, ['failed', 'expire', 'cancel'])) {
                $payment->order->update(['status' => $status === 'expire' ? 'expired' : 'failed']);
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'payment' => $payment->fresh(),
                'midtrans_status' => $data,
            ],
        ]);
    }
}