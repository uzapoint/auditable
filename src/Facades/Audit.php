<?php

namespace Uzapoint\Auditable\Facades;

use Illuminate\Support\Facades\Facade;
use Uzapoint\Auditable\Services\AuditPublisher;

class Audit extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AuditPublisher::class;
    }
}