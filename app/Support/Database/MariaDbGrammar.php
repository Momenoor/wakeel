<?php

namespace App\Support\Database;

use Illuminate\Database\Query\Grammars\MariaDbGrammar as Grammar;

/** MariaDb's, searching Arabic as it is meant (SearchesArabic). */
class MariaDbGrammar extends Grammar
{
    use SearchesArabic;
}
