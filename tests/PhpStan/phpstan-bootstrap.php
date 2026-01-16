<?php

/**
 * PHPStan Bootstrap File
 *
 * This file is loaded before PHPStan analyzes the codebase.
 * Define any constants or load any files needed for analysis.
 */

declare(strict_types=1);

// Define constants that may be needed during analysis
if (!defined('VENDOR_PATH')) {
    define('VENDOR_PATH', dirname(__DIR__, 2) . '/vendor/');
}

// Load custom PHPStan rules
require_once __DIR__ . '/Rules/NoConcreteClassTypeHintRule.php';
