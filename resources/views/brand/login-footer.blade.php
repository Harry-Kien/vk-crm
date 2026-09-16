{{-- Chân trang đăng nhập: tên pháp lý đầy đủ, hotline và website thật của văn phòng. --}}
<div style="margin-top:1.75rem;text-align:center;font-size:0.75rem;line-height:1.6;color:rgb(107 114 128)">
    <p style="margin:0;font-weight:600;color:rgb(75 85 99)">{{ config('vkcrm.brand.legal_name') }}</p>
    <p style="margin:0.15rem 0 0">
        <a href="tel:{{ config('vkcrm.brand.hotline') }}"
           style="color:inherit;text-decoration:none">{{ config('vkcrm.brand.hotline') }}</a>
        <span aria-hidden="true" style="opacity:.5;margin:0 .35rem">·</span>
        <a href="{{ config('vkcrm.brand.website') }}" target="_blank" rel="noopener noreferrer"
           style="color:inherit;text-decoration:none">{{ str(config('vkcrm.brand.website'))->after('://')->toString() }}</a>
    </p>
</div>
