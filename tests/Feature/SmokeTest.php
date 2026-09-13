<?php

it('boots the application', function () {
    expect(app()->environment())->toBe('testing');
});
