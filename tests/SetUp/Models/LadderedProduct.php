<?php

namespace Tests\SetUp\Models;

use DigitalSelf\LaravelCart\QuantityPriced;
use Illuminate\Database\Eloquent\Model;

/**
 * A volume-priced itemable: €25 for one, €22 each from two up.
 *
 * Shares the products table with {@see Product}, which implements only
 * Cartable — the two side by side are what the cart has to keep telling apart.
 */
class LadderedProduct extends Model implements QuantityPriced
{
    protected $table = 'products';

    protected $fillable = ['title', 'price'];

    public function getPrice(): float
    {
        return (float) $this->price;
    }

    public function getPriceByQuantity(int $quantity): float
    {
        return $quantity >= 2
            ? $quantity * 22.0
            : $quantity * 25.0;
    }
}
