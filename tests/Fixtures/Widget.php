<?php

namespace Uzapoint\Auditable\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Uzapoint\Auditable\Traits\Auditable;

/** A model with an encrypted, hidden column, like PaymentCredential. */
class Widget extends Model
{
    use Auditable;

    protected $table = 'widgets';

    protected $fillable = ['name', 'status', 'secret_note', 'api_key'];

    protected $hidden = ['secret_note'];

    protected $casts = ['secret_note' => 'encrypted'];
}
