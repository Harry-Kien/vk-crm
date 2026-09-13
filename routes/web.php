<?php

use Illuminate\Support\Facades\Route;

// Tên miền gốc dẫn thẳng tới portal khách hàng; panel nội bộ nằm ở /admin.
Route::redirect('/', '/portal');
