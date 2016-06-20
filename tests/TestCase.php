<?php

abstract class TestCase extends PHPUnit_Framework_TestCase
{
    protected function invokePrivateMethod($obj, $method, $args = array()) {
        if (!is_object($obj)) {
            $obj = new $obj;
        }
        $class = new ReflectionClass($obj);
        $method = $class->getMethod($method);
        $method->setAccessible(true);
        return $method->invokeArgs($obj, $args);
    }
}
