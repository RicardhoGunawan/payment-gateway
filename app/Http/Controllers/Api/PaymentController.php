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
     * Get available payment methods
     */
    public function getPaymentMethods(): JsonResponse
    {
        $methods = $this->midtransService->getAvailablePaymentMethods();

        return response()->json([
            'success' => true,
            'data' => $methods,
        ]);
    }

    /**
     * Create payment with selected method
     */
    public function createPayment(Order $order, Request $request): JsonResponse
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

        // Validate payment method
        $request->validate([
            'payment_method' => 'required|string|in:snap,qris,bca_va,bni_va,bri_va,mandiri_bill,cimb_va',
            'callback_url' => 'nullable|url',
        ]);

        $paymentMethod = $request->input('payment_method');
        $order->load('orderItems.product', 'user');

        // Prepare common transaction details
        $midtransOrderId = strtoupper($paymentMethod) . '-' . $order->id . '-' . time();

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
                'last_name' => $order->user->last_name ?? null,
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

        // Route to appropriate payment method
        switch ($paymentMethod) {
            case 'snap':
                return $this->createSnapPayment($order, $params, $midtransOrderId);
            
            case 'qris':
                return $this->createQrisPayment($order, $params, $midtransOrderId, $request);
            
            case 'bca_va':
            case 'bni_va':
            case 'bri_va':
            case 'cimb_va':
                $bank = explode('_', $paymentMethod)[0]; // Extract bank code
                return $this->createVirtualAccountPayment($order, $params, $midtransOrderId, $bank);
            
            case 'mandiri_bill':
                return $this->createMandiriBillPayment($order, $params, $midtransOrderId);
            
            default:
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid payment method',
                ], 422);
        }
    }

    /**
     * Create Snap payment
     */
    private function createSnapPayment(Order $order, array $params, string $midtransOrderId): JsonResponse
    {
        $result = $this->midtransService->createSnapTransaction($params);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create payment',
                'error' => $result['message'] ?? null,
            ], 500);
        }

        $payment = Payment::create([
            'order_id' => $order->id,
            'payment_method' => 'snap',
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
                'payment_method' => 'snap',
                'snap_token' => $result['snap_token'],
                'redirect_url' => $result['redirect_url'],
            ],
        ], 201);
    }

    /**
     * Create QRIS payment
     */
    private function createQrisPayment(Order $order, array $params, string $midtransOrderId, Request $request): JsonResponse
    {
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
                'payment_method' => 'qris',
                'order_id' => $result['order_id'],
                'transaction_id' => $result['transaction_id'],
                'transaction_status' => $result['transaction_status'],
                'qr_code_url' => $result['qr_code_url'],
                'deeplink_url' => $result['deeplink_url'],
                'actions' => $result['actions'],
            ],
        ], 201);
    }

    /**
     * Create Virtual Account payment (BCA, BNI, BRI, CIMB)
     */
    private function createVirtualAccountPayment(Order $order, array $params, string $midtransOrderId, string $bank): JsonResponse
    {
        $result = $this->midtransService->createBankTransferTransaction($params, $bank);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => "Failed to create {$bank} virtual account payment",
                'error' => $result['message'] ?? null,
            ], 500);
        }

        $payment = Payment::create([
            'order_id' => $order->id,
            'payment_method' => $bank . '_va',
            'midtrans_order_id' => $midtransOrderId,
            'midtrans_transaction_id' => $result['transaction_id'],
            'amount' => $order->total_amount,
            'status' => 'pending',
            'va_number' => $result['va_number'],
            'bank' => $result['bank'],
            'expiry_time' => $result['expiry_time'],
            'raw_response' => $result['raw_response'],
        ]);

        return response()->json([
            'success' => true,
            'message' => strtoupper($bank) . ' Virtual Account payment created successfully',
            'data' => [
                'payment_id' => $payment->id,
                'payment_method' => $bank . '_va',
                'order_id' => $result['order_id'],
                'transaction_id' => $result['transaction_id'],
                'transaction_status' => $result['transaction_status'],
                'va_number' => $result['va_number'],
                'bank' => $result['bank'],
                'gross_amount' => $result['gross_amount'],
                'expiry_time' => $result['expiry_time'],
            ],
        ], 201);
    }

    /**
     * Create Mandiri Bill Payment
     */
    private function createMandiriBillPayment(Order $order, array $params, string $midtransOrderId): JsonResponse
    {
        // Optional: customize bill info
        $params['echannel'] = [
            'bill_info1' => 'Payment For:',
            'bill_info2' => 'Order #' . $order->id,
        ];

        $result = $this->midtransService->createMandiriBillTransaction($params);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create Mandiri Bill payment',
                'error' => $result['message'] ?? null,
            ], 500);
        }

        $payment = Payment::create([
            'order_id' => $order->id,
            'payment_method' => 'mandiri_bill',
            'midtrans_order_id' => $midtransOrderId,
            'midtrans_transaction_id' => $result['transaction_id'],
            'amount' => $order->total_amount,
            'status' => 'pending',
            'bill_key' => $result['bill_key'],
            'biller_code' => $result['biller_code'],
            'expiry_time' => $result['expiry_time'],
            'raw_response' => $result['raw_response'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Mandiri Bill payment created successfully',
            'data' => [
                'payment_id' => $payment->id,
                'payment_method' => 'mandiri_bill',
                'order_id' => $result['order_id'],
                'transaction_id' => $result['transaction_id'],
                'transaction_status' => $result['transaction_status'],
                'bill_key' => $result['bill_key'],
                'biller_code' => $result['biller_code'],
                'gross_amount' => $result['gross_amount'],
                'expiry_time' => $result['expiry_time'],
            ],
        ], 201);
    }

    /**
     * Cancel payment
     */
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

    /**
     * Check payment status
     */
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