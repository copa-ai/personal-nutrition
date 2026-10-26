<?php

namespace Database\Factories;

use App\Enums\TipoFeedback;
use App\Models\Feedback;
use App\Models\Perfil;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Feedback> */
class FeedbackFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'perfil_id' => Perfil::factory(),
            'tipo' => fake()->randomElement(TipoFeedback::cases()),
            'elemento' => fake()->word(),
            'nota' => null,
        ];
    }

    public function gusta(string $elemento): static
    {
        return $this->state(['tipo' => TipoFeedback::Gusta, 'elemento' => $elemento]);
    }

    public function noGusta(string $elemento): static
    {
        return $this->state(['tipo' => TipoFeedback::NoGusta, 'elemento' => $elemento]);
    }
}
