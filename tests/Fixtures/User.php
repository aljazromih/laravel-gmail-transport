<?php

namespace AljazRomih\GmailTransport\Tests\Fixtures;

use AljazRomih\GmailTransport\HasGmailAccount;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasGmailAccount;

    protected $table = 'users';

    protected $guarded = [];
}
