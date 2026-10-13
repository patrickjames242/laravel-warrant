<?php

// The autoload map Composer would generate for this fixture project.
$baseDir = dirname(__DIR__, 2);

return [
    'App\\' => [$baseDir.'/app'],
    'Warrant\\' => [dirname($baseDir, 4).'/src'],
];
