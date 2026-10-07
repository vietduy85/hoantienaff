<?php

namespace App\Support;

final class AffiliateSyncLock
{
    public const KEY = 'affiliate:sync:lock';

    public const SECONDS = 7200;
}
