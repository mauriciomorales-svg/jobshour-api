<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class PioneerUsersSeeder extends Seeder
{
    public function run(): void
    {
        // Set all existing users as pioneers for testing
        User::query()->update([
            'is_pioneer' => true,
            'credits_balance' => 0, // pioneers don't need credits
        ]);

        $this->command->info('✅ All existing users set as pioneers');

        // Create test company user if doesn't exist
        $companyUser = User::firstOrCreate(
            ['email' => 'empresa@test.com'],
            [
                'name' => 'Constructora Los Andes',
                'password' => Hash::make('password'),
                'is_pioneer' => true,
                'is_company' => true,
                'company_rut' => '76.123.456-7',
                'company_razon_social' => 'Constructora Los Andes SpA',
                'company_giro' => 'Construcción y obras civiles',
                'type' => 'employer',
            ]
        );

        $this->command->info('✅ Test company user created: empresa@test.com / password');

        // Create test regular user with credits
        $regularUser = User::firstOrCreate(
            ['email' => 'regular@test.com'],
            [
                'name' => 'Usuario Regular',
                'password' => Hash::make('password'),
                'is_pioneer' => false,
                'credits_balance' => 5,
                'type' => 'employer',
            ]
        );

        $this->command->info('✅ Test regular user created: regular@test.com / password (5 credits)');
    }
}
