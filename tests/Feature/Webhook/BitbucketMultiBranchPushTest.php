<?php

use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(RefreshDatabase::class);

const BITBUCKET_MULTI_BRANCH_SECRET = 'bitbucket-multi-branch-secret';

beforeEach(function () {
    Server::flushIdentityMap();
    Queue::fake();

    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $project->id]);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->settings->update([
        'is_reachable' => true,
        'is_usable' => true,
        'force_disabled' => false,
    ]);
    $this->destination = $server->standaloneDockers()->firstOrFail();
});

function bitbucketMultiBranchApplication(TestCase $test, string $branch): Application
{
    return Application::create([
        'name' => "app-{$branch}",
        'git_repository' => 'acme/app',
        'git_branch' => $branch,
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'environment_id' => $test->environment->id,
        'destination_id' => $test->destination->id,
        'destination_type' => $test->destination->getMorphClass(),
        'manual_webhook_secret_bitbucket' => BITBUCKET_MULTI_BRANCH_SECRET,
    ])->refresh();
}

/**
 * A Bitbucket Cloud change of a repo:push event for one branch.
 */
function bitbucketPushChange(string $branch, string $hash, string $message = 'Change'): array
{
    return [
        'new' => ['type' => 'branch', 'name' => $branch, 'target' => ['hash' => $hash]],
        'commits' => [['hash' => $hash, 'message' => $message]],
    ];
}

function sendBitbucketPush(TestCase $test, array $changes): TestResponse
{
    $payload = json_encode([
        'push' => ['changes' => $changes],
        'repository' => ['full_name' => 'acme/app'],
    ], JSON_THROW_ON_ERROR);

    return $test->call('POST', '/webhooks/source/bitbucket/events/manual', [], [], [], [
        'REMOTE_ADDR' => '203.0.113.30',
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X-Event-Key' => 'repo:push',
        'HTTP_X-Hub-Signature' => 'sha256='.hash_hmac('sha256', $payload, BITBUCKET_MULTI_BRANCH_SECRET),
    ], $payload);
}

function bitbucketQueuedCommits(Application $application): array
{
    return ApplicationDeploymentQueue::query()
        ->where('application_id', $application->id)
        ->pluck('commit')
        ->all();
}

test('a push of two branches deploys the application of each branch with its own commit', function () {
    $main = bitbucketMultiBranchApplication($this, 'main');
    $develop = bitbucketMultiBranchApplication($this, 'develop');

    sendBitbucketPush($this, [
        bitbucketPushChange('develop', 'aaaaaaa1111111'),
        bitbucketPushChange('main', 'bbbbbbb2222222'),
    ])->assertOk();

    expect(bitbucketQueuedCommits($develop))->toBe(['aaaaaaa1111111'])
        ->and(bitbucketQueuedCommits($main))->toBe(['bbbbbbb2222222']);
});

test('a push of one branch only deploys the application of that branch', function () {
    $main = bitbucketMultiBranchApplication($this, 'main');
    $develop = bitbucketMultiBranchApplication($this, 'develop');

    sendBitbucketPush($this, [bitbucketPushChange('main', 'bbbbbbb2222222')])->assertOk();

    expect(bitbucketQueuedCommits($main))->toBe(['bbbbbbb2222222'])
        ->and(bitbucketQueuedCommits($develop))->toBe([]);
});

test('skip ci on one branch does not skip the other branch of the same push', function () {
    $main = bitbucketMultiBranchApplication($this, 'main');
    $develop = bitbucketMultiBranchApplication($this, 'develop');

    $response = sendBitbucketPush($this, [
        bitbucketPushChange('develop', 'aaaaaaa1111111', 'Docs [skip ci]'),
        bitbucketPushChange('main', 'bbbbbbb2222222'),
    ])->assertOk();

    expect(bitbucketQueuedCommits($develop))->toBe([])
        ->and(bitbucketQueuedCommits($main))->toBe(['bbbbbbb2222222'])
        ->and($response->getContent())->toContain('Skipping deployment.');
});

test('a deleted branch in the same push does not stop the deployment of a pushed branch', function () {
    $main = bitbucketMultiBranchApplication($this, 'main');

    sendBitbucketPush($this, [
        ['new' => null, 'old' => ['type' => 'branch', 'name' => 'feature', 'target' => ['hash' => 'ccccccc3333333']]],
        bitbucketPushChange('main', 'bbbbbbb2222222'),
    ])->assertOk();

    expect(bitbucketQueuedCommits($main))->toBe(['bbbbbbb2222222']);
});

test('a tag in the same push does not deploy the application of a branch with that name', function () {
    $main = bitbucketMultiBranchApplication($this, 'main');
    $release = bitbucketMultiBranchApplication($this, 'release');

    sendBitbucketPush($this, [
        ['new' => ['type' => 'tag', 'name' => 'release', 'target' => ['hash' => 'ddddddd4444444']]],
        bitbucketPushChange('main', 'bbbbbbb2222222'),
    ])->assertOk();

    expect(bitbucketQueuedCommits($main))->toBe(['bbbbbbb2222222'])
        ->and(bitbucketQueuedCommits($release))->toBe([]);
});

test('a push that only deletes a branch deploys nothing', function () {
    $main = bitbucketMultiBranchApplication($this, 'main');

    $response = sendBitbucketPush($this, [
        ['new' => null, 'old' => ['type' => 'branch', 'name' => 'main', 'target' => ['hash' => 'ccccccc3333333']]],
    ])->assertOk();

    expect(bitbucketQueuedCommits($main))->toBe([])
        ->and($response->getContent())->toContain('No branch found in the request.');
});
