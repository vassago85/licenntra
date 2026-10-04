<?php

use App\Support\RegisterNumber;
use App\Support\Vin;

it('accepts a 17 character vin without i o or q', function () {
    $vin = Vin::from(' jhh gd8jla 7k104512 ');

    expect($vin->value)->toBe('JHHGD8JLA7K104512')
        ->and($vin->grouped())->toBe('JHH GD8JLA 7K104512');
});

it('rejects a vin that is the wrong length or contains a forbidden letter', function (string $input) {
    expect(fn () => Vin::from($input))->toThrow(InvalidArgumentException::class);
})->with([
    'short' => 'ABC123',
    'letter i' => 'JHHGD8JLI7K104512',
    'letter o' => 'JHHGD8JLO7K104512',
    'letter q' => 'JHHGD8JLQ7K104512',
]);

it('normalises a vehicle register number', function () {
    expect(RegisterNumber::from(' tlx 914g ')->value)->toBe('TLX914G');
});

it('rejects a blank or punctuated register number', function (string $input) {
    expect(fn () => RegisterNumber::from($input))->toThrow(InvalidArgumentException::class);
})->with(['', 'TLX-914']);
