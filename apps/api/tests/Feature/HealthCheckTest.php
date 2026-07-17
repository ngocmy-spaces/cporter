<?php

it('reports the API as healthy', function () {
    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJson([
            'status' => 'ok',
            'service' => 'cporter',
        ]);
});
