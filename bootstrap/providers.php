<?php

use App\Providers\AppServiceProvider;
use App\Providers\ModularDomainServiceProvider;
use App\Providers\TypeScriptTransformerServiceProvider;

return [
    AppServiceProvider::class,
    ModularDomainServiceProvider::class,
    TypeScriptTransformerServiceProvider::class,
];
