<?php

namespace App\Modules\Core\Auth\Exceptions;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class EmployeeAccountInactiveException extends AccessDeniedHttpException {}
