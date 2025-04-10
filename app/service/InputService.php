<?php
// app/services/InputService.php
class InputService
{
    public function get($key)
    {
        return Input::get($key);
    }

    public function post($key)
    {
        return Input::post($key);
    }
}
