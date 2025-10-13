<?php
if (php_sapi_name()==='cli-server') {
  $p = __DIR__.parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
  if (is_file($p)) return false;
}
require __DIR__.'/webhook.php';
