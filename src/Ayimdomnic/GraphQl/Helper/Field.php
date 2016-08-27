<?php
/**
 * Created by PhpStorm.
 * User: Ayimdomnic
 * Date: 8/27/2016
 * Time: 3:09 AM.
 */
namespace Ayimdomnic\GraphQl\Helper;

use Illuminate\Support\Fluent;

class Field extends Fluent
{
    //first attributes

    public function attributes()
    {
        return [];
    }

    //first type
    public function type()
    {
        return [];
    }

    public function args()
    {
        return [];
    }

    protected function getResolver()
    {
        if (!method_exists($this, 'resolve')) {
            return;
        }

        $resolver = [$this, 'resolve'];

        return function () use ($resolver) {
            $args = func_get_args();

            return call_user_func_array($resolver, $args);
        };
    }

    /**
     * Get the attributes from the container.
     *
     * @return array
     */
    public function getAttributes()
    {
        $attributes = $this->attributes();
        $args = $this->args();

        $attributes = array_merge($this->attributes, [
            'args' => $this->args(),
        ], $attributes);

        $type = $this->type();
        if (isset($type)) {
            $attributes['type'] = $type;
        }

        $resolver = $this->getResolver();
        if (isset($resolver)) {
            $attributes['resolve'] = $resolver;
        }

        return $attributes;
    }

    /**
     * Convert the Fluent instance to an array.
     *
     * @return array
     */
    public function toArray()
    {
        return $this->getAttributes();
    }

    /**
     * Dynamically retrieve the value of an attribute.
     *
     * @param string $key
     *
     * @return mixed
     */
    public function __get($key)
    {
        $attributes = $this->getAttributes();

        return isset($attributes[$key]) ? $attributes[$key] : null;
    }

    /**
     * Dynamically check if an attribute is set.
     *
     * @param string $key
     *
     * @return void
     */
    public function __isset($key)
    {
        $attributes = $this->getAttributes();

        return isset($attributes[$key]);
    }
}
