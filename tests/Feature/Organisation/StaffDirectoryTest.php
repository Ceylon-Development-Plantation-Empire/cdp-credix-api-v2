<?php

declare(strict_types=1);

use Tests\Support\Fixtures;

/*
| The staff dropdowns (/users/list): branch-scoped, filterable by user type, and recovery agencies
| (no branch of their own) are offered to every branch.
*/

it('lists the users of the branch plus the recovery agencies, filterable by user type', function () {
    $colombo = Fixtures::branch('Colombo');
    $kandy = Fixtures::branch('Kandy');

    $manager = Fixtures::staffInBranch($colombo, 'Recovery Case Assign');
    $colleague = Fixtures::staffInBranch($colombo);
    $elsewhere = Fixtures::staffInBranch($kandy);
    $agency = userWith('agency');

    $ids = $this->actingAs($manager, 'api')->getJson('/api/v1/users/list')->assertOk()->collect('data')->pluck('id');
    expect($ids)->toContain($colleague->id, $agency->id)->not->toContain($elsewhere->id);

    $this->actingAs($manager, 'api')->getJson('/api/v1/users/list?user_type=agency')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $agency->id);
    $this->actingAs($manager, 'api')->getJson('/api/v1/users/list?user_type=customer')->assertStatus(422);
});
