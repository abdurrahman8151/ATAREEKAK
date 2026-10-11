<?php

namespace Tests\Feature\Review;

use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * RV-45 (owner ruling: file a row and fix). SignupController::serverError returned the raw exception
 * message whenever APP_DEBUG was on, so a database fault reached the client with SQL text. It must
 * return only the generic message at every debug setting. The exception text stays in the log.
 *
 * The fault is real: users.first_name is VARCHAR(191) NOT NULL in strict mode, and a 200-character
 * first name passes the validator (max:255) but is rejected by the database.
 */
class RV45SignupServerErrorTest extends TestCase
{
    public function test_database_fault_with_debug_on_returns_only_the_generic_message(): void
    {
        Config::set('app.debug', true);

        $response = $this->postJson('/api/auth/signup', $this->payloadWithOverlongFirstName());

        $response->assertStatus(500)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('error', 'An error occurred');

        $content = $response->getContent();
        $this->assertStringNotContainsString('Data too long', $content);
        $this->assertStringNotContainsString('SQLSTATE', $content);
        $this->assertStringNotContainsString('first_name', $content);
    }

    public function test_database_fault_with_debug_off_returns_only_the_generic_message(): void
    {
        Config::set('app.debug', false);

        $response = $this->postJson('/api/auth/signup', $this->payloadWithOverlongFirstName());

        $response->assertStatus(500)
            ->assertJsonPath('error', 'An error occurred');
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
    }

    private function payloadWithOverlongFirstName(): array
    {
        return [
            'first_name' => str_repeat('A', 200),
            'last_name' => 'Tester',
            'email' => 'rv45.signup.'.uniqid().'@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'gender' => 'M',
            'address' => 'دمشق',
        ];
    }
}
