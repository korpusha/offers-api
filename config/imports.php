<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Import Retention
    |--------------------------------------------------------------------------
    |
    | How many days of imports to keep. Anything older is removed by the
    | scheduled "model:prune" command, taking its staged offers with it.
    | Offers still in the catalogue keep their data but lose the link to
    | the import that last touched them.
    |
    */

    'retention_days' => (int) env('IMPORT_RETENTION_DAYS', 90),

];
