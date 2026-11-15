<?php

namespace AljazRomih\GmailTransport\Tests\Fixtures;

use AljazRomih\GmailTransport\HasGmailAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An owner that is soft-deleted.
 */
class Team extends Model
{
    use HasGmailAccount, SoftDeletes;

    protected $table = 'teams';

    protected $guarded = [];
}
