<?php

declare(strict_types=1);

namespace App\Models;

use Core\Database;
use Core\Model;

class User extends Model
{
    public function __construct()
    {
        $this->table = 'users';
        parent::__construct();
    }

    public function test()
    {
        return self::query()->get();
    }
}
