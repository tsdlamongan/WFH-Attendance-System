<?php

namespace App\Enums;

enum UserRole: string
{
    case SUPER_ADMIN = 'super_admin';
    case MANAGER = 'manager';
    case EMPLOYEE = 'employee';
}
