<?php

namespace App\Support\Database;

use App\Support\ArabicSearch;
use Illuminate\Database\Query\Builder;

/**
 * A query grammar's "like" searches for Arabic, both sides as they are searched (App\Support\ArabicSearch) — the
 * app's own (where(…, 'like', …), whereLike()) and Filament's (tables, selects, global search: a raw
 * "{column} like ? escape '!'", whatever is searched for).
 */
trait SearchesArabic
{
    protected function whereBasic(Builder $query, $where)
    {
        if (! ArabicSearch::searches($where)) {
            return parent::whereBasic($query, $where);
        }

        return ArabicSearch::sql($this->wrap($where['column'])).' '.str_replace('?', '??', $where['operator']).' '
            .ArabicSearch::sql($this->parameter($where['value']));
    }

    protected function whereRaw(Builder $query, $where)
    {
        $sql = parent::whereRaw($query, $where);

        return preg_match("/^(.+?) (not like|like) \\? escape '!'$/s", $sql, $search) === 1
            ? ArabicSearch::sql($search[1])." {$search[2]} ".ArabicSearch::sql('?')." escape '!'"
            : $sql;
    }
}
