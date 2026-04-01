<?php

namespace Database\Seeders;

use App\Models\Customer;
use Illuminate\Database\Seeder;

class CustomerSeeder extends Seeder
{
    public function run(): void
    {
        Customer::updateOrCreate(
            ['customer_code' => 'DEFAULT'],
            [
                'customer_name' => 'Default Customer',
                'description' => 'Default customer for testing',
                'is_active' => true,
            ]
        );
    }
}
