<?php
function format_name($first, $last = null, $suffix)   // 可选参数在必选参数前（8.0 弃用）
{
    return trim($first . ' ' . $last . $suffix);
}

function find_user(int $id = null)                    // 隐式可空类型 8.4 弃用
{
    return $id;
}

function old_filter($v)
{
    return filter_var($v, FILTER_SANITIZE_MAGIC_QUOTES);  // 8.0 已移除
}

function read_csv($fp)
{
    return fgetcsv($fp, 1000, ',');                     // 8.4 起需显式 escape 参数
}

function mb_test($s)
{
    return mb_strrpos($s, 'a', 'UTF-8');                // 第三参数当编码 8.0 已移除
}
