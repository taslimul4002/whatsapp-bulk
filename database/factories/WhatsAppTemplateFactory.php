<?php

namespace Database\Factories;

use App\Models\WhatsAppTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WhatsAppTemplate>
 */
class WhatsAppTemplateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_account_id' => '123',
            'name' => fake()->unique()->regexify('[a-z]{12}'),
            'language' => 'en_US',
            'category' => 'UTILITY',
            'status' => 'DRAFT',
            'components' => [['type' => 'BODY', 'text' => 'Hello']],
        ];
    }
}
