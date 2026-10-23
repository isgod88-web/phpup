<?php
class User
{
    var $name;                         // var 关键字（仍可用）

    function User($name)               // PHP4 风格构造函数，8.0 起不再是构造函数
    {
        $this->name = $name;
    }

    function greet()
    {
        $this->nickname = 'x';         // 动态属性 8.2 弃用（不能自动修复）
        $cls = get_class();            // 无参调用 8.3 弃用
        return 'Hi ' . $this->name;
    }

    public static function __set_state($a)
    {
        return new self($a['name']);
    }

    final private function secret()   // final private 8.0 警告
    {
    }

    public function __toString()
    {
        return $this->name;
    }
}
