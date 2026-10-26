<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * No crea usuarios: el del panel se crea con `php artisan make:filament-user`.
     */
    public function run(): void
    {
        $this->call(HogarSeeder::class);
    }
}
