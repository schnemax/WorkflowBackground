<?php
return [
  // Default-Schema (kannst du pro Typ überschreiben)
  'default' => [
    'name'   => 'extract',
    'schema' => [
      'type' => 'object',
      'additionalProperties' => false,
      'properties' => [
        'issuer_name'     => ['type'=>'string'],
        'invoice_date'    => ['type'=>['string','null'], 'pattern'=>'^\d{4}-\d{2}-\d{2}$'],
        'invoice_number'  => ['type'=>'string'],
        'invoice_amount'  => ['type'=>['number','null']],
        'issuer_iban'     => ['type'=>'string'],
        'issuer_bic'      => ['type'=>'string'],
        'payment_purpose' => ['type'=>'string'],
        'direct_debit'    => ['type'=>'boolean'],
      ],
      'required' => ['issuer_name','direct_debit']
    ]
  ],

  // Spezifisch für "Rechnung" (hier gleich dem Default)
  'Rechnung' => null,
  // Beispiel: abweichende Felder für "Gutschrift" etc.
];
