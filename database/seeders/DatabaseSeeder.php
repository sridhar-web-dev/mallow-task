<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Model events stay enabled (no WithoutModelEvents): PlanObserver must run so cache
     * stores that survive migrate:fresh, such as Redis, never serve a previous seed's catalogue.
     */
    public function run(): void
    {
        $this->call(DemoSeeder::class);
    }
}
