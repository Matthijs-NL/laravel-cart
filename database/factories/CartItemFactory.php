<?php
namespace DigitalSelf\LaravelCart\Database\Factories;

use DigitalSelf\LaravelCart\Models\Cart;
use DigitalSelf\LaravelCart\Models\CartItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class CartItemFactory extends Factory
{
    protected $model = CartItem::class;

    public function definition()
    {
        return [
            'cart_id' => Cart::factory(),
            'quantity' => $this->faker->numberBetween(1, 10),
            'itemable_id' => config('laravel-cart.product_model')::factory()->create()->id,
            'itemable_type' => config('laravel-cart.product_model'),
        ];
    }
}
