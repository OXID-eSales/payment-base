<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

// Use credentials from config.inc.php
return [
    'dbname' => 'example',
    'user' => 'root',
    'password' => 'root',
    'host' => 'mysql',
    'port' => 3306,
    'driver' => 'pdo_mysql',
    'charset' => 'utf8',
    'driverOptions' => [
        \PDO::MYSQL_ATTR_INIT_COMMAND => 'SET @@SESSION.sql_mode=\'\''
    ]
];
