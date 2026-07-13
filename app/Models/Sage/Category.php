<?php

declare(strict_types=1);

namespace App\Models\Sage;

/**
 * Sage `_mtblCategories` — a property classification (Residential H/D,
 * Commercial, …) used for rating, usage and zoning. `cCategory` is the code and
 * `cCategoryDescription` the human name; the `bIs*Category` flags say which
 * dimension (usage / zonal / rating) it applies to.
 */
class Category extends SageModel
{
    protected $table = '_mtblCategories';

    protected $primaryKey = 'idCategory';
}
