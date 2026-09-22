<?php

use App\Models\ClientUser;
use App\Models\User;

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | This option defines the default authentication "guard" and password
    | reset "broker" for your application. You may change these values
    | as required, but they're a perfect start for most applications.
    |
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    |
    | Next, you may define every authentication guard for your application.
    | Of course, a great default configuration has been defined for you
    | which utilizes session storage plus the Eloquent user provider.
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | Supported: "session"
    |
    */

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],

        // Portal khách hàng: guard riêng, bảng riêng, không bao giờ dùng chung với nhân sự.
        'client' => [
            'driver' => 'session',
            'provider' => 'client_users',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | If you have multiple user tables or models you may configure multiple
    | providers to represent the model / table. These providers may then
    | be assigned to any extra authentication guards you have defined.
    |
    | Supported: "database", "eloquent"
    |
    */

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => env('AUTH_MODEL', User::class),
        ],

        'client_users' => [
            'driver' => 'eloquent',
            'model' => ClientUser::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    |
    | These configuration options specify the behavior of Laravel's password
    | reset functionality, including the table utilized for token storage
    | and the user provider that is invoked to actually retrieve users.
    |
    | The expiry time is the number of minutes that each reset token will be
    | considered valid. This security feature keeps tokens short-lived so
    | they have less time to be guessed. You may change this as needed.
    |
    | The throttle setting is the number of seconds a user must wait before
    | generating more password reset tokens. This prevents the user from
    | quickly generating a very large amount of password reset tokens.
    |
    */

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],

        'client_users' => [
            'provider' => 'client_users',
            'table' => 'client_password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Confirmation Timeout
    |--------------------------------------------------------------------------
    |
    | Here you may define the number of seconds before a password confirmation
    | window expires and users are asked to re-enter their password via the
    | confirmation screen. By default, the timeout lasts for three hours.
    |
    */

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

    /*
    |--------------------------------------------------------------------------
    | Khoảng đệm thời gian của một lần đăng nhập (SPEC §10.10)
    |--------------------------------------------------------------------------
    |
    | `Filament\Auth\Pages\Login::authenticate()` bọc cả nhánh thành công lẫn nhánh thất bại
    | trong một `Illuminate\Support\Timebox` dài đúng `auth.timebox_duration` micro giây, để một
    | email có thật và một email bịa ra mất CÙNG một khoảng thời gian. Đó là nửa còn lại của
    | SPEC §10.10: hai câu trả lời giống hệt nhau vẫn phân biệt được nếu một cái về nhanh hơn.
    |
    | Con số phải lớn hơn tổng chi phí thật của nhánh CHẬM NHẤT, nếu không `Timebox` không còn
    | gì để đệm và chênh lệch lộ ra nguyên vẹn. Đo trên container dev (bcrypt cost 12, đúng
    | `BCRYPT_ROUNDS` của `.env.example`): `Hash::check()` mất 152–160 ms, trung bình 157 ms.
    | Cộng thêm một dòng activity log và hai lần ghi cache của bộ đếm thì mặc định 200 ms của
    | framework chỉ còn khoảng 30 ms dư — đủ hôm nay, và không đủ trên một máy chủ chia sẻ chậm
    | hơn hoặc khi `BCRYPT_ROUNDS` được nâng.
    |
    | 500 ms là nửa giây khách phải chờ ở màn hình đăng nhập — một cái giá nhìn thấy được, trả
    | có chủ ý để lấy khoảng dư gấp ba chi phí băm. Ghim ở đây chứ không để mặc định vì một con
    | số không ai viết ra là một con số không ai kiểm lại khi phần cứng đổi.
    |
    */

    'timebox_duration' => (int) env('AUTH_TIMEBOX_DURATION', 500_000),

];
