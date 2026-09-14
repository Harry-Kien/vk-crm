<?php

it('uses a separate password reset table for client users', function () {
    expect(config('auth.passwords.client_users.table'))->toBe('client_password_reset_tokens')
        ->and(config('auth.passwords.users.table'))->toBe('password_reset_tokens');
});
