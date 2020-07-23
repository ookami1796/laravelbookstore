<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookCategory extends Model
{
    public $table = 'book_category';
    protected $fillable = ['book_id', 'category_id'];
}
