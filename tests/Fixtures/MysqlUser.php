<?php

namespace Lookout\Tests\Fixtures;

/**
 * A user on the optional local MySQL connection of McpTokensTableTest (users.id INT UNSIGNED).
 */
class MysqlUser extends User
{
    protected $connection = 'lookout_mysql';

    protected $table = 'users';
}
