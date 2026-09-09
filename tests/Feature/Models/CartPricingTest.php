<?php

use DigitalSelf\LaravelCart\Models\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\SetUp\Models\LadderedProduct;
use Tests\SetUp\Models\Product;
use Tests\SetUp\Models\User;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Cart arithmetic
|--------------------------------------------------------------------------
| Two rules, and they only work if they live in one place:
|
|   - a QuantityPriced itemable is asked to price the cart's WHOLE holding of
|     it, summed across lines;
|   - a line with a price override bills at that price and its quantity is not
|     evidence of volume.
|
| Both used to be re-derived by every caller that needed a total, which is how
| a checkout came to charge one number while a coupon measured another.
*/

function cartFor(): Cart
{
    // Unique per call: one test builds two carts to compare them.
    $user = User::query()->create([
        'name' => 'Test',
        'email' => uniqid('cart', true).'@example.com',
    ]);

    // customer_id, not user_id: the owner column was renamed and the rest of
    // this suite still passes the old name, which is why 26 of its tests fail
    // before any of this work.
    return Cart::query()->create(['customer_id' => $user->id]);
}

function addLine(Cart $cart, $itemable, int $quantity, array $options = []): void
{
    $cart->items()->create([
        'itemable_id' => $itemable->id,
        'itemable_type' => $itemable::class,
        'quantity' => $quantity,
        'options' => $options,
    ]);
}

it('prices a Cartable-only itemable exactly as quantity times its price', function () {
    // The shape every existing implementor has. It must not change.
    $product = Product::query()->create(['title' => 'Flat', 'price' => 45]);
    $cart = cartFor();

    addLine($cart, $product, 1);
    addLine($cart, $product, 1);

    expect($cart->calculatedPriceByQuantity())->toBe(90.0);
});

it('asks a laddered itemable for the whole cart holding, not one line', function () {
    $product = LadderedProduct::query()->create(['title' => 'Ladder', 'price' => 25]);
    $cart = cartFor();

    // Two lines of one each — two children of different ages on one entry
    // product. Priced per line this is 25 + 25; the checkout charges the
    // two-and-up band.
    addLine($cart, $product, 1);
    addLine($cart, $product, 1);

    expect($cart->quantityByItemable())->toBe([LadderedProduct::class.':'.$product->id => 2])
        ->and($cart->calculatedPriceByQuantity())->toBe(44.0)
        // Each line carries its share at the band rate, so a caller summing
        // only some lines gets a figure comparable with the whole.
        ->and(array_values($cart->lineTotals()))->toBe([22.0, 22.0]);
});

it('reaches the same total however the quantity is split across lines', function () {
    $product = LadderedProduct::query()->create(['title' => 'Ladder', 'price' => 25]);

    $split = cartFor();
    addLine($split, $product, 1);
    addLine($split, $product, 1);

    $single = cartFor();
    addLine($single, $product, 2);

    expect($split->calculatedPriceByQuantity())->toBe($single->calculatedPriceByQuantity());
});

it('bills an overridden line at the agreed price', function () {
    $product = LadderedProduct::query()->create(['title' => 'Ladder', 'price' => 25]);
    $cart = cartFor();

    addLine($cart, $product, 2, ['price_override' => 10.0]);

    expect($cart->calculatedPriceByQuantity())->toBe(20.0);
});

it('keeps an overridden line out of the volume it would otherwise create', function () {
    $product = LadderedProduct::query()->create(['title' => 'Ladder', 'price' => 25]);
    $cart = cartFor();

    // One at the catalogue price, one at a negotiated price. The negotiated one
    // is not evidence that two were bought, so the first must stay on the
    // single-unit band.
    addLine($cart, $product, 1);
    addLine($cart, $product, 1, ['price_override' => 10.0]);

    expect($cart->quantityByItemable())->toBe([LadderedProduct::class.':'.$product->id => 1])
        ->and($cart->calculatedPriceByQuantity())->toBe(35.0);
});

it('treats a zero override as no override', function () {
    // An unset form field arriving as 0 must not zero a paid line; a free line
    // is expressed with a coupon. Matches the `! empty()` test this replaced.
    $product = Product::query()->create(['title' => 'Flat', 'price' => 45]);
    $cart = cartFor();

    addLine($cart, $product, 1, ['price_override' => 0]);

    expect($cart->items()->first()->priceOverride())->toBeNull()
        ->and($cart->calculatedPriceByQuantity())->toBe(45.0);
});

it('counts a deleted itemable as nothing rather than failing', function () {
    $product = Product::query()->create(['title' => 'Flat', 'price' => 45]);
    $cart = cartFor();
    addLine($cart, $product, 1);

    $product->delete();

    expect($cart->calculatedPriceByQuantity())->toBe(0.0);
});

it('prices two different itemables independently', function () {
    $flat = Product::query()->create(['title' => 'Flat', 'price' => 45]);
    $ladder = LadderedProduct::query()->create(['title' => 'Ladder', 'price' => 25]);
    $cart = cartFor();

    addLine($cart, $flat, 1);
    addLine($cart, $ladder, 1);

    // One of each: neither reaches a second unit, so no band applies and the
    // flat product is untouched by the other's ladder.
    expect($cart->calculatedPriceByQuantity())->toBe(70.0);
});

it('keeps a flat product flat while a laddered one in the same cart reaches its band', function () {
    // The mixed cart, with BOTH sides past one unit — the case where a bug
    // would actually show. Quantity is keyed per itemable, so three of the flat
    // product must not be read as volume for the laddered one, and the
    // laddered one's band must not be applied to the flat one.
    $flat = Product::query()->create(['title' => 'Flat', 'price' => 45]);
    $ladder = LadderedProduct::query()->create(['title' => 'Ladder', 'price' => 25]);
    $cart = cartFor();

    addLine($cart, $flat, 3);
    addLine($cart, $ladder, 2);

    expect($cart->quantityByItemable())->toBe([
        Product::class.':'.$flat->id => 3,
        LadderedProduct::class.':'.$ladder->id => 2,
    ]);

    // 3 × €45 flat, plus 2 × €22 on the two-and-up band.
    expect($cart->calculatedPriceByQuantity())->toBe(135.0 + 44.0);

    $totals = array_values($cart->lineTotals());
    expect($totals)->toBe([135.0, 44.0]);
});

it('splits a laddered product across lines beside a flat one without cross-contamination', function () {
    // Same again with the laddered product spread over two lines, which is how
    // band-sharing ticket types actually arrive: the ladder still sees 2, and
    // the flat lines are still priced one at a time.
    $flat = Product::query()->create(['title' => 'Flat', 'price' => 45]);
    $ladder = LadderedProduct::query()->create(['title' => 'Ladder', 'price' => 25]);
    $cart = cartFor();

    addLine($cart, $flat, 1);
    addLine($cart, $ladder, 1);
    addLine($cart, $flat, 1);
    addLine($cart, $ladder, 1);

    expect($cart->quantityByItemable())->toBe([
        Product::class.':'.$flat->id => 2,
        LadderedProduct::class.':'.$ladder->id => 2,
    ])
        // €45 + €22 + €45 + €22. The flat product has no band to reach, and
        // two of it does not discount it.
        ->and(array_values($cart->lineTotals()))->toBe([45.0, 22.0, 45.0, 22.0])
        ->and($cart->calculatedPriceByQuantity())->toBe(134.0);
});
