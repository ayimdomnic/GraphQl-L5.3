<?php
/**
 * Created by PhpStorm.
 * User: Ayimdomnic
 * Date: 8/27/2016
 * Time: 5:12 AM.
 */
namespace Ayimdomnic\GraphQl\Exceptions;

use GraphQL\Error;

class ValidationError extends Error
{
    public $validator;

    public function setValidator($validator)
    {
        $this->validator = $validator;

        return $this;
    }

    public function getValidatorMessages()
    {
        return $this->validator ? $this->validator->messages() : [];
    }
}
