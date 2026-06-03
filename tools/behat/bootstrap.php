<?php

declare(strict_types=1);

// Loaded (via the files-autoload below) BEFORE the root project's autoloader,
// so PHP 8.4 deprecation notices from transitive deps (thecodingmachine/safe,
// phpactor) are silenced before those files are required. Mirrors
// test/bootstrap.php + phpunit.xml.dist so Behat behaves identically to the
// unit suite no matter how the binary is invoked (CLI flags, IDE, CI).
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '1');

// Silence the index/cache warmer's stderr chatter that the real server emits on
// the Initialized event (mirrors phpunit.xml.dist's env). Set via putenv so it
// works regardless of how the binary is launched -- shell env-prefixes don't
// propagate through the containerized `php` proxy in this project.
putenv('XPHP_LSP_QUIET=1');
