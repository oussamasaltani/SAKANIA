<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Insert roles
        DB::table('roles')->insert([
            ['id' => 1, 'name' => 'tenant'],
            ['id' => 2, 'name' => 'owner'],
            ['id' => 3, 'name' => 'admin'],
        ]);

        $adminPassword = $this->seedPassword('DEFAULT_ADMIN_PASSWORD', 'admin123');
        $ownerPassword = $this->seedPassword('DEFAULT_OWNER_PASSWORD', 'owner123');
        $tenantPassword = $this->seedPassword('DEFAULT_TENANT_PASSWORD', 'tenant123');

        // Insert default admin
        $this->command?->info('Default admin account: admin@sakania.ma / ' . $adminPassword);

        DB::table('users')->insert([
            'full_name' => 'Sakania Admin',
            'email' => 'admin@sakania.ma',
            'password_hash' => Hash::make($adminPassword),
            'role_id' => 3,
            'is_active' => true,
            'created_at' => now(),
        ]);

        // Insert demo owner
        DB::table('users')->insert([
            'full_name' => 'Demo Owner',
            'email' => 'owner@sakania.ma',
            'password_hash' => Hash::make($ownerPassword),
            'phone' => '+212600000001',
            'role_id' => 2,
            'is_active' => true,
            'created_at' => now(),
        ]);

        // Insert demo tenant
        DB::table('users')->insert([
            'full_name' => 'Demo Tenant',
            'email' => 'tenant@sakania.ma',
            'password_hash' => Hash::make($tenantPassword),
            'phone' => '+212600000002',
            'role_id' => 1,
            'is_active' => true,
            'created_at' => now(),
        ]);

        // Insert second owner
        DB::table('users')->insert([
            'full_name' => 'Youssef Benani',
            'email' => 'youssef@sakania.ma',
            'password_hash' => Hash::make($ownerPassword),
            'phone' => '+212600000003',
            'role_id' => 2,
            'is_active' => true,
            'created_at' => now(),
        ]);

        // Insert second tenant
        DB::table('users')->insert([
            'full_name' => 'Fatima Zahra',
            'email' => 'fatima@sakania.ma',
            'password_hash' => Hash::make($tenantPassword),
            'phone' => '+212600000004',
            'role_id' => 1,
            'is_active' => true,
            'created_at' => now(),
        ]);

        $properties = [
            [
                'owner_id' => 2,
                'title' => 'Modern Apartment in Maarif',
                'description' => 'Beautiful modern apartment located in the heart of Maarif, Casablanca. Features a spacious living room, fully equipped kitchen, and two bedrooms with en-suite bathrooms. Close to shopping centers and public transport.',
                'location' => 'Maarif, Casablanca',
                'type' => 'apartment',
                'area' => 95.00,
                'price_per_month' => 8500.00,
                'status' => 'approved',
                'rooms' => 3,
                'bathrooms' => 2,
                'floor' => 4,
                'capacity' => 4,
                'amenities' => json_encode(['wifi', 'air_conditioning', 'parking', 'elevator', 'furnished']),
                'created_at' => now()->subDays(10),
            ],
            [
                'owner_id' => 2,
                'title' => 'Cozy Studio near Ain Diab',
                'description' => 'Charming studio apartment just minutes from Ain Diab beach. Perfect for a single person or couple. Includes modern appliances and a balcony with sea views.',
                'location' => 'Ain Diab, Casablanca',
                'type' => 'studio',
                'area' => 45.00,
                'price_per_month' => 4500.00,
                'status' => 'approved',
                'rooms' => 1,
                'bathrooms' => 1,
                'floor' => 2,
                'capacity' => 2,
                'amenities' => json_encode(['wifi', 'air_conditioning', 'furnished', 'balcony']),
                'created_at' => now()->subDays(8),
            ],
            [
                'owner_id' => 2,
                'title' => 'Family Villa in Anfa',
                'description' => 'Spacious villa in the prestigious Anfa district. Features a private garden, swimming pool, 4 bedrooms, and a large living area. Ideal for families.',
                'location' => 'Anfa, Casablanca',
                'type' => 'villa',
                'area' => 250.00,
                'price_per_month' => 25000.00,
                'status' => 'approved',
                'rooms' => 5,
                'bathrooms' => 3,
                'floor' => 0,
                'capacity' => 8,
                'amenities' => json_encode(['wifi', 'air_conditioning', 'parking', 'pool', 'garden', 'security']),
                'created_at' => now()->subDays(5),
            ],
            [
                'owner_id' => 2,
                'title' => 'Office Space in Business Center',
                'description' => 'Professional office space in a modern business center. Open plan layout with meeting room, reception area, and high-speed internet. Near Casa Finance City.',
                'location' => 'Casa Finance City, Casablanca',
                'type' => 'office',
                'area' => 120.00,
                'price_per_month' => 15000.00,
                'status' => 'approved',
                'rooms' => 4,
                'bathrooms' => 2,
                'floor' => 8,
                'capacity' => 10,
                'amenities' => json_encode(['wifi', 'air_conditioning', 'parking', 'elevator', 'security', 'meeting_room']),
                'created_at' => now()->subDays(3),
            ],
            [
                'owner_id' => 4,
                'title' => 'Elegant House in Gauthier',
                'description' => 'Elegant house in the trendy Gauthier neighborhood. Recently renovated with high-quality finishes. Features a rooftop terrace and city views.',
                'location' => 'Gauthier, Casablanca',
                'type' => 'house',
                'area' => 180.00,
                'price_per_month' => 18000.00,
                'status' => 'approved',
                'rooms' => 4,
                'bathrooms' => 2,
                'floor' => 0,
                'capacity' => 6,
                'amenities' => json_encode(['wifi', 'air_conditioning', 'parking', 'terrace', 'furnished']),
                'created_at' => now()->subDays(7),
            ],
            [
                'owner_id' => 4,
                'title' => 'Budget Apartment in Sidi Moumen',
                'description' => 'Affordable apartment in a developing area. Great value for money with good public transport links to the city center.',
                'location' => 'Sidi Moumen, Casablanca',
                'type' => 'apartment',
                'area' => 65.00,
                'price_per_month' => 3000.00,
                'status' => 'approved',
                'rooms' => 2,
                'bathrooms' => 1,
                'floor' => 3,
                'capacity' => 3,
                'amenities' => json_encode(['wifi']),
                'created_at' => now()->subDays(2),
            ],
            [
                'owner_id' => 4,
                'title' => 'Luxury Apartment in CIL',
                'description' => 'High-end luxury apartment in the exclusive CIL neighborhood. Premium finishes, smart home system, and stunning ocean views.',
                'location' => 'CIL, Casablanca',
                'type' => 'apartment',
                'area' => 140.00,
                'price_per_month' => 20000.00,
                'status' => 'pending',
                'rooms' => 3,
                'bathrooms' => 2,
                'floor' => 10,
                'capacity' => 4,
                'amenities' => json_encode(['wifi', 'air_conditioning', 'parking', 'elevator', 'furnished', 'security', 'gym']),
                'created_at' => now()->subDay(),
            ],
        ];

        foreach ($properties as $property) {
            DB::table('properties')->insert($property);
        }

        // Add some favorites (users 3,5 are tenants; properties 1-6 are approved)
        DB::table('favorites')->insert([
            ['user_id' => 3, 'property_id' => 1],
            ['user_id' => 3, 'property_id' => 2],
            ['user_id' => 5, 'property_id' => 1],
            ['user_id' => 5, 'property_id' => 3],
        ]);

        // Add a reservation (tenant 3 reserves property 1 from owner 2)
        DB::table('reservations')->insert([
            'property_id' => 1,
            'tenant_id' => 3,
            'start_date' => now()->addMonth()->toDateString(),
            'end_date' => now()->addMonths(4)->toDateString(),
            'status' => 'accepted',
            'created_at' => now()->subDays(2),
        ]);

        // Add a payment for the accepted reservation
        DB::table('payments')->insert([
            'reservation_id' => 1,
            'tenant_id' => 3,
            'total_amount' => 8500.00,
            'amount' => 8500.00,
            'paid_amount' => 8500.00,
            'remaining_balance' => 0.00,
            'method' => 'card',
            'status' => 'paid',
            'transaction_ref' => 'TXN-' . strtoupper(uniqid()),
            'payment_date' => now()->subDay(),
        ]);

        // Add a contract for the paid reservation
        DB::table('contracts')->insert([
            'reservation_id' => 1,
            'owner_id' => 2,
            'tenant_id' => 3,
            'status' => 'active',
            'created_at' => now(),
        ]);
    }

    private function seedPassword(string $envKey, string $fallback): string
    {
        $password = env($envKey, $fallback);

        if (app()->environment('production') && $password === $fallback) {
            return Str::password(20);
        }

        return $password;
    }
}
