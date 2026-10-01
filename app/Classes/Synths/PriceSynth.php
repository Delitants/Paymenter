<?php

namespace App\Classes\Synths;

use App\Classes\Price;
use Livewire\Mechanisms\HandleComponents\Synthesizers\Synth;

/**
 * Class PriceSynth
 */
class PriceSynth extends Synth
{
    public static $key = 'price';

    public static function match($target)
    {
        return $target instanceof Price;
    }

    public static function dehydrate($instance)
    {
        return [[
            'price' => $instance->price_decimal,
            'currency' => $instance->currency,
            'setup_fee' => $instance->setup_fee_decimal,
            'has_setup_fee' => $instance->has_setup_fee,
            'is_free' => $instance->is_free,
            'dontShowUnavailablePrice' => $instance->dontShowUnavailablePrice,
            'tax' => $instance->tax,
            'setup_fee_tax' => $instance->setup_fee_tax,
            'discount' => $instance->discount,
            'original_price' => $instance->original_price_decimal,
            'original_setup_fee' => $instance->original_setup_fee_decimal,
            'formatted' => $instance->formatted,
        ], []];
    }

    public static function hydrate($instance)
    {
        $price = new Price(['price' => $instance['price'], 'setup_fee' => $instance['setup_fee'], 'currency' => $instance['currency'], 'tax_amount' => $instance['tax'], 'setup_tax_amount' => $instance['setup_fee_tax']], $instance['is_free'], $instance['dontShowUnavailablePrice']);
        $price->tax = $instance['tax'];
        $price->setup_fee_tax = $instance['setup_fee_tax'];
        $price->discount = $instance['discount'];
        $price->setOriginalAmounts((string) $instance['original_price'], (string) $instance['original_setup_fee']);

        return $price;
    }
}
