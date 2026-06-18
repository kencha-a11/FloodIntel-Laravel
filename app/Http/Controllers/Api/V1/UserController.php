<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index()
    {
        // Sample array data na magsisilbing database rows mo pansamantala
        $users = [
            [
                'id' => 1,
                'name' => 'Danilo Santos',
                'email' => 'danilo@example.com',
                'role' => 'Barangay Admin'
            ],
            [
                'id' => 2,
                'name' => 'Maria Clara',
                'email' => 'maria@example.com',
                'role' => 'Data Encoder'
            ],
            [
                'id' => 3,
                'name' => 'Juan Dela Cruz',
                'email' => 'juan@example.com',
                'role' => 'Staff'
            ],
            [
                'id' => 4,
                'name' => 'Dela Cruz',
                'email' => 'John@example.com',
                'role' => 'member'
            ]
        ];

        // Awtomatikong ikoconvert ng Laravel ito into JSON wrapper na may status code 200
        return response()->json($users);
    }
}
