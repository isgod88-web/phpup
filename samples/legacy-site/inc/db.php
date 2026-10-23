<?php
// mysql_* 扩展 7.0 已移除，无法自动迁移（需人工改为 mysqli/PDO）
$link = mysql_connect('localhost', 'user', 'pass');
mysql_select_db('demo', $link);
$res = mysql_query("SELECT * FROM users WHERE name='" . mysql_real_escape_string($_GET['name']) . "'");
while ($row = mysql_fetch_assoc($res)) {
    echo $row['name'];
}

$key = mcrypt_create_iv(16, MCRYPT_RAND);   // mcrypt 7.2 已移除
