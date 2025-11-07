<?php

namespace App\Services;

use Midtrans\Config;
use Midtrans\Snap;
use Midtrans\Transaction;
use Midtrans\Notification;
use Illuminate\Support\Facades\Http;

class MidtransService
{
    public function __construct()
    {
        $this->configureMidtrans();
    }

    private function configureMidtrans(): void
    {
        Config::$serverKey = config('midtrans.server_key');
        Config::$isProduction = config('midtrans.is_production');
        Config::$isSanitized = config('midtrans.is_sanitized');
        Config::$is3ds = config('midtrans.is_3ds');
    }

    /**
     * Get base URL for Core API
     */
    private function getCoreApiUrl(): string
    {
        return config('midtrans.is_production')
            ? 'https://api.midtrans.com/v2'
            : 'https://api.sandbox.midtrans.com/v2';
    }

    /**
     * Get authorization header
     */
    private function getAuthHeader(): string
    {
        return 'Basic ' . base64_encode(config('midtrans.server_key') . ':');
    }

    /**
     * Create GoPay/QRIS transaction using Core API
     */
    public function createQrisTransaction(array $params): array
    {
        try {
            $url = $this->getCoreApiUrl() . '/charge';
            
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => $this->getAuthHeader(),
            ])->post($url, [
                'payment_type' => 'gopay',
                'transaction_details' => $params['transaction_details'],
                'item_details' => $params['item_details'] ?? null,
                'customer_details' => $params['customer_details'] ?? null,
                'gopay' => $params['gopay'] ?? null,
            ]);

            if ($response->failed()) {
                throw new \Exception($response->json('status_message') ?? 'Failed to create QRIS transaction');
            }

            $data = $response->json();

            // Extract QR code URL and deeplink
            $qrCodeUrl = null;
            $deeplinkUrl = null;

            if (isset($data['actions']) && is_array($data['actions'])) {
                foreach ($data['actions'] as $action) {
                    if ($action['name'] === 'generate-qr-code') {
                        $qrCodeUrl = $action['url'];
                    } elseif ($action['name'] === 'deeplink-redirect') {
                        $deeplinkUrl = $action['url'];
                    }
                }
            }

            return [
                'success' => true,
                'transaction_id' => $data['transaction_id'],
                'order_id' => $data['order_id'],
                'transaction_status' => $data['transaction_status'],
                'qr_code_url' => $qrCodeUrl,
                'deeplink_url' => $deeplinkUrl,
                'actions' => $data['actions'] ?? [],
                'raw_response' => $data,
            ];
        } catch (\Exception $e) {
            \Log::error('Midtrans QRIS Error: ' . $e->getMessage());
            
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Create Snap transaction
     */
    public function createSnapTransaction(array $params): array
    {
        try {
            $snapToken = Snap::getSnapToken($params);
            
            return [
                'success' => true,
                'snap_token' => $snapToken,
                'redirect_url' => config('midtrans.is_production') 
                    ? "https://app.midtrans.com/snap/v2/vtweb/{$snapToken}"
                    : "https://app.sandbox.midtrans.com/snap/v2/vtweb/{$snapToken}",
            ];
        } catch (\Exception $e) {
            \Log::error('Midtrans Snap Error: ' . $e->getMessage());
            
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check transaction status
     */
    public function checkStatus(string $orderId): array
    {
        try {
            $status = Transaction::status($orderId);
            
            return [
                'success' => true,
                'data' => json_decode(json_encode($status), true),
            ];
        } catch (\Exception $e) {
            \Log::error('Midtrans Status Check Error: ' . $e->getMessage());
            
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Cancel transaction
     */
    public function cancelTransaction(string $orderId): array
    {
        try {
            $result = Transaction::cancel($orderId);
            
            return [
                'success' => true,
                'data' => json_decode(json_encode($result), true),
            ];
        } catch (\Exception $e) {
            \Log::error('Midtrans Cancel Error: ' . $e->getMessage());
            
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Verify webhook notification
     */
    public function verifyNotification(array $payload): ?Notification
    {
        try {
            return new Notification($payload);
        } catch (\Exception $e) {
            \Log::error('Midtrans Notification Verify Error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Map Midtrans status to internal status
     */
    public function mapStatus(string $transactionStatus, string $fraudStatus = null): string
    {
        if ($transactionStatus == 'capture') {
            return $fraudStatus == 'accept' ? 'success' : 'pending';
        } elseif ($transactionStatus == 'settlement') {
            return 'success';
        } elseif (in_array($transactionStatus, ['cancel', 'deny'])) {
            return 'failed';
        } elseif ($transactionStatus == 'expire') {
            return 'expire';
        } elseif ($transactionStatus == 'pending') {
            return 'pending';
        }

        return 'pending';
    }

    /**
     * Verify signature from webhook
     */
    public function verifySignature(string $orderId, string $statusCode, string $grossAmount, string $signatureKey): bool
    {
        $serverKey = config('midtrans.server_key');
        $hash = hash('sha512', $orderId . $statusCode . $grossAmount . $serverKey);
        
        return $hash === $signatureKey;
    }
}