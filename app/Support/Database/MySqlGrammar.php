<?php

namespace App\Support\Database;

use Illuminate\Database\Query\Grammars\MySqlGrammar as Grammar;

/** MySql's, searching Arabic as it is meant (SearchesArabic). */
class MySqlGrammar extends Grammar
{
    use SearchesArabic;
}
