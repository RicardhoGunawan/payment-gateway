<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $products = [
            [
                'sku' => 'PROD-001',
                'name' => 'Laptop Gaming ASUS ROG',
                'price' => 15000000,
                'stock' => 10,
                'description' => 'Laptop gaming dengan spesifikasi tinggi untuk gaming dan multimedia',
            ],
            [
                'sku' => 'PROD-002',
                'name' => 'iPhone 15 Pro Max',
                'price' => 20000000,
                'stock' => 15,
                'description' => 'Smartphone flagship Apple dengan kamera terbaik',
            ],
            [
                'sku' => 'PROD-003',
                'name' => 'Samsung Galaxy S24 Ultra',
                'price' => 18000000,
                'stock' => 20,
                'description' => 'Flagship Android dengan S-Pen dan kamera 200MP',
            ],
            [
                'sku' => 'PROD-004',
                'name' => 'MacBook Pro M3',
                'price' => 25000000,
                'stock' => 8,
                'description' => 'MacBook Pro dengan chip M3 untuk profesional',
            ],
            [
                'sku' => 'PROD-005',
                'name' => 'Sony WH-1000XM5',
                'price' => 5000000,
                'stock' => 30,
                'description' => 'Headphone noise cancelling terbaik di kelasnya',
            ],
            [
                'sku' => 'PROD-006',
                'name' => 'iPad Pro 12.9"',
                'price' => 16000000,
                'stock' => 12,
                'description' => 'Tablet premium Apple untuk produktivitas',
            ],
            [
                'sku' => 'PROD-007',
                'name' => 'Dell XPS 15',
                'price' => 22000000,
                'stock' => 7,
                'description' => 'Laptop premium untuk profesional dan content creator',
            ],
            [
                'sku' => 'PROD-008',
                'name' => 'Apple Watch Series 9',
                'price' => 7000000,
                'stock' => 25,
                'description' => 'Smartwatch dengan fitur kesehatan lengkap',
            ],
            [
                'sku' => 'PROD-009',
                'name' => 'Samsung Galaxy Tab S9',
                'price' => 12000000,
                'stock' => 18,
                'description' => 'Tablet Android premium dengan S-Pen',
            ],
            [
                'sku' => 'PROD-010',
                'name' => 'AirPods Pro (2nd Gen)',
                'price' => 3500000,
                'stock' => 40,
                'description' => 'Earbuds wireless dengan noise cancelling',
            ],
        ];

        foreach ($products as $product) {
            Product::create($product);
        }
    }
}