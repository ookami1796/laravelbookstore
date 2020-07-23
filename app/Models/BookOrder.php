<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookOrder extends Model
{
    public $table = 'book_order';
    protected $fillable = ['book_id', 'order_id', 'quantity'];
}
