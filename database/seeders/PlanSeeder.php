<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    /**
     * Platform plan catalog (§54): FREE / STARTER / BUSINESS / ENTERPRISE,
     * measured by active employees (5 / 25 / 100 / custom).
     */
    public function run(): void
    {
        $plans = [
            ['slug' => 'free', 'name' => 'Free', 'employee_limit' => 5, 'price_monthly' => 0],
            ['slug' => 'starter', 'name' => 'Starter', 'employee_limit' => 25, 'price_monthly' => 29],
            ['slug' => 'business', 'name' => 'Business', 'employee_limit' => 100, 'price_monthly' => 99],
            ['slug' => 'enterprise', 'name' => 'Enterprise', 'employee_limit' => null, 'price_monthly' => 299],
        ];

        foreach ($plans as $plan) {
            Plan::firstOrCreate(['slug' => $plan['slug']], $plan);
        }
    }
}
