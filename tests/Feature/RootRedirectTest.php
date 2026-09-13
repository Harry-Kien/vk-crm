<?php

it('sends the root url to the portal', function () {
    $this->get('/')->assertRedirect('/portal');
});
