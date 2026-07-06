<?php

namespace Tests\Unit;

use App\Helpers\ValidateAccessEmployee;
use App\Models\Employee;
use App\Models\User;
use Tests\TestCase;

class ValidateAccessEmployeeTest extends TestCase
{
    public function test_level_two_user_from_general_direction_12_can_access_related_general_directions(): void
    {
        $user = new User();
        $user->level_id = 2;
        $user->general_direction_id = 12;

        $allowedEmployee = new Employee();
        $allowedEmployee->general_direction_id = 13;

        $anotherAllowedEmployee = new Employee();
        $anotherAllowedEmployee->general_direction_id = 11;

        $notAllowedEmployee = new Employee();
        $notAllowedEmployee->general_direction_id = 15;

        $this->assertTrue(ValidateAccessEmployee::validateUser($user, $allowedEmployee));
        $this->assertTrue(ValidateAccessEmployee::validateUser($user, $anotherAllowedEmployee));
        $this->assertFalse(ValidateAccessEmployee::validateUser($user, $notAllowedEmployee));
    }
}
