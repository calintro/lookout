<?php

namespace Lookout\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    protected $guarded = [];

    protected $casts = ['is_admin' => 'boolean'];
}
