<?php

namespace App\View\Components\CreditCard;

use Illuminate\View\Component;
use Illuminate\View\View;

class Layout extends Component
{
    public function __construct(
        public string $title = 'Thẻ tín dụng',
        public string $subtitle = 'Quản lý và theo dõi các thẻ tín dụng của bạn',
        public string $active = 'index',
    ) {}

    public function render(): View
    {
        return view('credit-card.layout');
    }
}
