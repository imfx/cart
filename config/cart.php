<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Session Identifier
    |--------------------------------------------------------------------------
    |
    */

    'identifier' => 'cart',

    /*
    |--------------------------------------------------------------------------
    | Default tax rate
    |--------------------------------------------------------------------------
    |
    | This default tax rate will be used when you make a class implement the
    | Taxable interface and use the HasTax trait.
    |
    */

    'tax' => 0,

    /*
    |--------------------------------------------------------------------------
    | Cart database settings
    |--------------------------------------------------------------------------
    |
    | Here you can set the connection that the cart should use when
    | storing and restoring a cart.
    |
    */

    'database' => [
        'connection' => null,
        'table'      => 'cart',
    ],

    /*
    |--------------------------------------------------------------------------
    | Destroy the cart on user logout
    |--------------------------------------------------------------------------
    |
    | When this option is set to 'true' the cart will automatically
    | destroy all cart instances when the user logs out.
    |
    */

    'destroy_on_logout' => false,

];