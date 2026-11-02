<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One login attempt, recorded whether it succeeded or failed.
 *
 * A failed attempt carries no user or token and never stores the submitted
 * login identifier or password.
 */
#[Fillable(['user_id', 'personal_access_token_id', 'outcome', 'ip_address', 'user_agent'])]
class LoginEvent extends Model
{
    public const OUTCOME_SUCCESS = 'success';

    public const OUTCOME_FAILED = 'failed';

    public $timestamps = false;
}
