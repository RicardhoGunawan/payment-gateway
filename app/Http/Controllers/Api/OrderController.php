<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateOrderRequest;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderController extends Controller
{
    public function index(): JsonResponse
    {
        $orders = auth()->user()
            ->orders()
            ->with(['orderItems.product'])
            ->latest()
            ->get();

        return $this->successResponse($orders);
    }

    public function store(CreateOrderRequest $request): JsonResponse
    {
        try {
            DB::beginTransaction();

            $user = auth()->user();

            // ✅ Proses item order & hitung total
            [$orderItems, $totalAmount] = $this->prepareOrderItems($request->items);

            // ✅ Tambahkan biaya pengiriman
            $shippingAmount = $request->shipping_amount ?? 0;
            $totalAmount += $shippingAmount;

            // ✅ Data pengiriman (ambil dari request atau profil user)
            $shippingData = $this->resolveShippingData($request, $user);
            if (!$shippingData) {
                return $this->errorResponse(
                    'Shipping name, address, and phone are required or must exist in the user profile.',
                    422
                );
            }

            // ✅ Buat order
            $order = Order::create(array_merge($shippingData, [
                'user_id'         => $user->id,
                'total_amount'    => $totalAmount,
                'shipping_amount' => $shippingAmount,
                'status'          => 'pending',
            ]));

            // ✅ Simpan order items
            $order->orderItems()->createMany($orderItems);

            DB::commit();

            $order->load('orderItems.product');

            return $this->successResponse($order, 'Order created successfully', 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Order creation error: ' . $e->getMessage());
            return $this->errorResponse('Failed to create order', 500);
        }
    }

    public function show(Order $order): JsonResponse
    {
        if ($order->user_id !== auth()->id()) {
            return $this->errorResponse('Unauthorized', 403);
        }

        $order->load(['orderItems.product', 'payment']);
        return $this->successResponse($order);
    }

    // ===========================
    // 🔧 PRIVATE HELPER METHODS
    // ===========================

    /**
     * Prepare order items and calculate total amount
     */
    private function prepareOrderItems(array $items): array
    {
        $orderItems = [];
        $totalAmount = 0;

        foreach ($items as $item) {
            $product = Product::findOrFail($item['product_id']);

            if ($product->stock < $item['quantity']) {
                abort(response()->json([
                    'success' => false,
                    'message' => "Insufficient stock for product: {$product->name}",
                ], 422));
            }

            $subtotal = $product->price * $item['quantity'];
            $totalAmount += $subtotal;

            $orderItems[] = [
                'product_id' => $product->id,
                'quantity'   => $item['quantity'],
                'unit_price' => $product->price,
                'subtotal'   => $subtotal,
            ];

            // Reduce stock
            $product->decrement('stock', $item['quantity']);
        }

        return [$orderItems, $totalAmount];
    }

    /**
     * Resolve shipping data (from request or user profile)
     */
    private function resolveShippingData($request, $user): ?array
    {
        $name    = $request->shipping_name ?? $user->name;
        $address = $request->shipping_address ?? $user->address;
        $phone   = $request->shipping_phone ?? $user->phone;

        if (!$name || !$address || !$phone) {
            return null;
        }

        return [
            'shipping_name'    => $name,
            'shipping_address' => $address,
            'shipping_phone'   => $phone,
        ];
    }

    /**
     * Standard success response format
     */
    private function successResponse($data, string $message = 'Success', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], $status);
    }

    /**
     * Standard error response format
     */
    private function errorResponse(string $message, int $status = 400): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }
}
