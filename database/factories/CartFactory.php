<?php
namespace DigitalSelf\LaravelCart\Database\Factories;

use DigitalSelf\LaravelCart\Models\Cart;
use Illuminate\Database\Eloquent\Factories\Factory;

class CartFactory extends Factory
{
    protected $model = Cart::class;

    public function definition()
    {
        return [
            'customer_id' => config('laravel-cart.customer_model')::factory()->create()->id,
            'canceled_at' => $this->faker->optional(0.1)->dateTime,
            'completed_at' => $this->faker->optional(0.1)->dateTime,
        ];
    }
}
