<?php

namespace App\Jobs;

use App\Models\Payment;
use App\Models\PaymentNotification;
use App\Services\MidtransService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class ProcessPaymentNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public PaymentNotification $notification
    ) {}

    public function handle(MidtransService $midtransService): void
    {
        try {
            DB::beginTransaction();

            $payload = $this->notification->payload;

            // Find payment by midtrans_order_id
            $payment = Payment::where('midtrans_order_id', $payload['order_id'])->first();

            if (!$payment) {
                \Log::warning('Payment not found for notification', [
                    'order_id' => $payload['order_id']
                ]);
                
                $this->notification->update([
                    'status' => 'failed',
                    'note' => 'Payment not found',
                    'processed_at' => now(),
                ]);
                
                DB::commit();
                return;
            }

            // Link notification to payment
            $this->notification->update([
                'payment_id' => $payment->id,
            ]);

            // Map status
            $transactionStatus = $payload['transaction_status'];
            $fraudStatus = $payload['fraud_status'] ?? null;
            $newStatus = $midtransService->mapStatus($transactionStatus, $fraudStatus);

            // Update payment
            $payment->update([
                'status' => $newStatus,
                'midtrans_transaction_id' => $payload['transaction_id'] ?? null,
                'raw_response' => array_merge($payment->raw_response ?? [], [
                    'last_notification' => $payload,
                ]),
            ]);

            // Update order status
            $order = $payment->order;
            
            if ($newStatus === 'success' && $order->status !== 'paid') {
                $order->update(['status' => 'paid']);
            } elseif ($newStatus === 'failed' && !in_array($order->status, ['paid', 'cancelled'])) {
                $order->update(['status' => 'failed']);
            } elseif ($newStatus === 'expire' && !in_array($order->status, ['paid', 'cancelled'])) {
                $order->update(['status' => 'expired']);
            } elseif ($newStatus === 'cancel' && !in_array($order->status, ['paid'])) {
                $order->update(['status' => 'cancelled']);
            }

            // Mark notification as processed
            $this->notification->update([
                'status' => 'processed',
                'processed_at' => now(),
                'note' => "Payment status updated to: {$newStatus}, Order status: {$order->status}",
            ]);

            DB::commit();

            \Log::info('Payment notification processed successfully', [
                'payment_id' => $payment->id,
                'order_id' => $order->id,
                'status' => $newStatus,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            \Log::error('Failed to process payment notification', [
                'notification_id' => $this->notification->id,
                'error' => $e->getMessage(),
            ]);

            $this->notification->update([
                'status' => 'failed',
                'note' => $e->getMessage(),
                'processed_at' => now(),
            ]);

            throw $e;
        }
    }
}