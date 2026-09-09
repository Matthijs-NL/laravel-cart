<?php

namespace DigitalSelf\LaravelCart;

/**
 * A Cartable whose price depends on how many are being bought.
 *
 * Optional and additive. An itemable that implements only {@see Cartable} is
 * still priced as `quantity × getPrice()`, which is what a flat catalogue
 * wants and what every existing implementor gets.
 *
 * The part of this contract that actually matters is WHICH quantity the cart
 * passes: everything the cart holds of this itemable, summed across every
 * line — not one line's quantity. A cart is free to split the same itemable
 * over several lines (different dates, different options, different people),
 * and the buyer has done nothing there that should cost them a volume
 * discount. Leaving that unsaid is how four call sites ended up disagreeing
 * about it, two reading per line and two reading per itemable, with the
 * checkout charging one answer and the coupon measuring the other.
 *
 * Implementations must be VOLUME functions: the quantity selects one rate and
 * every unit is charged at it. A graduated function — where the first N units
 * cost more than the rest — cannot be divided back across lines without an
 * allocation and rounding policy, and {@see Models\Cart::lineTotals()} has
 * none. Return the TOTAL for `$quantity` units, not the unit price.
 */
interface QuantityPriced extends Cartable
{
    public function getPriceByQuantity(int $quantity): float;
}
