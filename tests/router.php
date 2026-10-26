<?php
// PHP yerleşik sunucusu için .htaccess kurallarının karşılığı (yalnızca yerel test).
$uri = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$root = dirname(__DIR__) . '/site';
if (preg_match('#^/_app(/|$)#', $uri)) {
    http_response_code(403);
    echo 'Forbidden';
    return true;
}
if (preg_match('#^/api(/|$)#', $uri)) {
    require $root . '/api/index.php';
    return true;
}
if (preg_match('#^/panel/?$#', $uri)) {
    require $root . '/panel/index.php';
    return true;
}
$file = $root . $uri;
if (is_dir($file)) {
    $file = rtrim($file, '/') . '/index.html';
}
if (is_file($file)) {
    return false; // statik dosyayı sunucu versin
}
http_response_code(404);
readfile($root . '/404.html');
return true;
