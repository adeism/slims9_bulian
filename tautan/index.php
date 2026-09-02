<?php
// Tautan entry - serve index.html for PHP built-in server compatibility
// If accessed via PHP server, serve the SPA
if (php_sapi_name() === 'cli-server') {
  $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
  $file = __DIR__ . $path;
  if ($path !== '/' && file_exists($file) && is_file($file)) {
    return false; // serve static file
  }
}
readfile(__DIR__ . '/index.html');
