<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Facturación — datos del vendedor (HotelIgnite)
    |--------------------------------------------------------------------------
    |
    | Van en el presupuesto que un municipio necesita para abrir el expediente
    | de compra y, más adelante, en la factura. Sin razón social y CUIT el
    | presupuesto sale marcado como incompleto: Compras no puede usarlo.
    |
    */

    'seller' => [
        'legal_name' => env('BILLING_LEGAL_NAME'),
        'tax_id' => env('BILLING_TAX_ID'),
        'iva_condition' => env('BILLING_IVA_CONDITION'),
        'address' => env('BILLING_ADDRESS'),
        'email' => env('BILLING_EMAIL'),
    ],

    // Validez del presupuesto. 30 días alcanza para que el expediente llegue a
    // la orden de compra en una adjudicación simple.
    'quote_validity_days' => (int) env('BILLING_QUOTE_VALIDITY_DAYS', 30),

];
