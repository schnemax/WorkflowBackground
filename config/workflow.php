<?php
return [
  'S' => [ // Key => Tag-Name (Anzeige)
    'INIT' => 'WF:Init',
    'PRUEFEN'=>'WF:Pruefen',
    'PRUEFEN2' => 'WF:Wiedervorlage',
    'BUSY'=>'WF:Busy',
    'UNVOLL'=>'WF:Daten_unvollständig',
    'APP_REQ'=>'WF:Rechnungsfreigabe_erforderlich',
    'APP_OK'=>'WF:Rechnungsfreigabe_erfolgt',
    'SEPA'=>'WF:SEPA_erzeugt',
    'CLOSE'=>'WF:Close',
    'ERROR'=>'WF:Error',
  ],
  'ALLOWED' => [
    'INIT' => ['INIT','PRUEFEN', 'CLOSE'],
    'PRUEFEN'=>['BUSY','UNVOLL','APP_REQ','APP_OK','ERROR'],
    'PRUEFEN2' => ['BUSY','PRUEFEN','UNVOLL','APP_REQ','ERROR'],
    'BUSY'=>['UNVOLL','APP_REQ','ERROR'],
    'UNVOLL'=>['APP_REQ','PRUEFEN','ERROR'],
    'APP_REQ'=>['APP_OK','APP_REJ','PRUEFEN','PRUEFEN2','UNVOLL','ERROR'],
    'APP_REJ'=>['PRUEFEN','PRUEFEN2'],
    'APP_OK'=>['APP_REQ','SEPA','CLOSE','ERROR'],
    'SEPA'=>['CLOSE','ERROR'],
    'CLOSE'=>[],
    'ERROR'=>['PRUEFEN'],
  ],
];
