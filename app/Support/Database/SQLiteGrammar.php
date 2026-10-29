<?php

namespace App\Support\Database;

use Illuminate\Database\Query\Grammars\SQLiteGrammar as Grammar;

/** SQLite's, searching Arabic as it is meant (SearchesArabic). */
class SQLiteGrammar extends Grammar
{
    use SearchesArabic;
}
