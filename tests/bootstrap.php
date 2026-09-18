<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

// Minimal config() stand-in for infrastructure tests (see the file header);
// guarded so a real framework bootstrap is never shadowed.
require __DIR__.'/Support/config_test_helper.php';
