<?php
// 样例：PHP 7.3 时代常见的旧写法（用于测试 phpup 工具）
include __DIR__ . '/inc/functions.php';
include __DIR__ . '/inc/db.php';
include __DIR__ . '/inc/User.php';

define('SITE_NAME', 'demo', true);          // 第三个参数（大小写不敏感常量）8.0 已移除

$config = array('a' => 1, 'b' => 2);
while (list($key, $val) = each($config)) {  // each() 8.0 已移除
    echo "$key=$val\n";
}

$sorter = create_function('$a,$b', 'return $a - $b;');  // create_function 8.0 已移除

$str = 'hello';
echo $str{0};                               // 花括号取字符 8.0 已移除

$price = (real) '1.5';                      // (real) 转换 8.0 已移除
if (is_real($price)) {
    echo money_format('%i', $price);        // money_format 8.0 已移除
}

echo implode($config, ',');                 // implode 参数反序 8.0 已移除

$name = 'world';
echo "hello ${name}\n";                     // ${} 字符串插值 8.2 弃用

$ok = $a ? 1 : $b ? 2 : 3;                  // 嵌套三元不加括号 8.0 报错

$hash = utf8_encode('abc');                 // utf8_encode 8.2 弃用

$parts = split(',', 'a,b');                 // split 7.0 已移除
if (ereg('^[a-z]+$', 'abc')) {              // ereg 7.0 已移除
    echo 'ok';
}

$magic = get_magic_quotes_gpc();            // 8.0 已移除

$ch = curl_init('https://example.com');
curl_close($ch);                            // 8.5 弃用

$out = `ls -la`;                            // 反引号 8.5 弃用
