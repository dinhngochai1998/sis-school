<?php
/**
 * @Author Edogawa Conan
 * @Date   Jun 01, 2022
 */

namespace App\Http\Middleware;

class TrimStrings extends TransformsRequest
{
    /**
     * The attributes that should not be trimmed.
     *
     * @var array
     */
    protected array $except = [
        //
    ];
    /**
     * Transform the given value.
     *
     * @param string $key
     * @param  mixed $value
     *
     * @return mixed
     */
    protected function transform(string $key, mixed $value) : mixed
    {
        if (in_array($key, $this->except, true)) {
            return $value;
        }
        return is_string($value) ? trim($value) : $value;
    }
}
