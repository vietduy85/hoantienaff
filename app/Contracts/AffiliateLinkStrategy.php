<?php

namespace App\Contracts;

use App\Models\LinkRequest;

interface AffiliateLinkStrategy
{
    public function handle(LinkRequest $linkRequest): void;
}
