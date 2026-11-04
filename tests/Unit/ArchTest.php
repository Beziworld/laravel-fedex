<?php

declare(strict_types=1);

arch('every source file declares strict types')
    ->expect('Beziworld\Fedex')
    ->toUseStrictTypes();

arch('no debugging statements are left behind')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'die'])
    ->not->toBeUsed();

arch('data objects are immutable')
    ->expect('Beziworld\Fedex\Data')
    ->toBeReadonly();

arch('exceptions share the package base exception')
    ->expect('Beziworld\Fedex\Exceptions')
    ->toExtend('Beziworld\Fedex\Exceptions\FedexException')
    ->ignoring('Beziworld\Fedex\Exceptions\FedexException');
