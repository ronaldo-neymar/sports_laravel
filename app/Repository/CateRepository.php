<?php
namespace App\Repository;

use App\Models\Category;
use Illuminate\Config\Repository;

class CateRepository extends Repository {

    public function getCate()
    {
      return Category::where('parent_id','=',null)->get();
    }
}