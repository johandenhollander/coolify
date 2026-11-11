<?php

use App\Livewire\Project\Application\Previews;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\GitlabApp;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    config(['app.maintenance.driver' => 'file']);

    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(
        ['id' => 0],
        [
            'id' => 0,
            'is_dns_validation_enabled' => false,
        ]
    ));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $key = PrivateKey::withoutEvents(fn () => PrivateKey::forceCreate([
        'uuid' => (string) Str::uuid(),
        'name' => 'Test Key',
        'private_key' => 'test-key',
        'team_id' => $this->team->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]));

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $key->id,
        'ip' => '203.0.113.10',
    ]);

    $this->server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
    ]);

    StandaloneDocker::withoutEvents(function () {
        $this->destination = StandaloneDocker::firstOrCreate(
            ['server_id' => $this->server->id, 'network' => 'coolify'],
            ['uuid' => (string) Str::uuid(), 'name' => 'test-docker']
        );
    });

    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);

    $this->application = Application::factory()->create([
        'uuid' => (string) Str::uuid(),
        'name' => 'Preview App',
        'preview_url_template' => '{{pr_id}}.{{domain}}',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'fqdn' => null,
        'redirect' => 'both',
        'build_pack' => 'nixpacks',
    ]);

    $this->application->settings()->update([
        'is_container_label_readonly_enabled' => true,
    ]);
});

it('shows preview settings and persists changes independently of preview inputs', function () {
    Livewire::test(Previews::class, ['application' => $this->application])
        ->assertSee('PR deployment access')
        ->assertSet('isPreviewDeploymentsEnabled', false)
        ->set('manualPullRequestId', -1)
        ->set('isPreviewDeploymentsEnabled', true)
        ->set('isPrDeploymentsPublicEnabled', true)
        ->call('savePreviewSettings')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    expect($this->application->fresh()->settings)
        ->is_preview_deployments_enabled->toBeTrue()
        ->is_pr_deployments_public_enabled->toBeTrue();

    Livewire::test(Previews::class, ['application' => $this->application->fresh()])
        ->assertSet('isPreviewDeploymentsEnabled', true)
        ->assertSet('isPrDeploymentsPublicEnabled', true)
        ->set('isPreviewDeploymentsEnabled', false)
        ->call('savePreviewSettings');

    expect($this->application->fresh()->settings->is_preview_deployments_enabled)->toBeFalse();
});

it('renders preview deployment enablement as a section action', function () {
    Livewire::test(Previews::class, ['application' => $this->application])
        ->assertSee('Enable preview deployments')
        ->assertDontSeeHtml('id="isPreviewDeploymentsEnabled"')
        ->call('togglePreviewDeployments')
        ->assertSee('Disable preview deployments')
        ->assertSet('isPreviewDeploymentsEnabled', true);

    expect($this->application->fresh()->settings->is_preview_deployments_enabled)->toBeTrue();
});

it('does not load GitHub pull requests for GitLab sources', function () {
    $gitlabApp = GitlabApp::create([
        'name' => 'Self-hosted GitLab',
        'api_url' => 'https://gitlab.example.com/api/v4',
        'html_url' => 'https://gitlab.example.com',
        'team_id' => $this->team->id,
    ]);
    $this->application->update(['source_id' => $gitlabApp->id, 'source_type' => GitlabApp::class]);

    Livewire::test(Previews::class, ['application' => $this->application->fresh()])
        ->assertSee('PR deployment access')
        ->assertDontSee('Load pull requests')
        ->call('load_prs')
        ->assertSet('rate_limit_remaining', 0)
        ->assertDispatched('error');
});

it('does not show git preview settings for non-git applications', function (string $buildPack, ?string $dockerfile) {
    $this->application->update(['build_pack' => $buildPack, 'dockerfile' => $dockerfile]);

    Livewire::test(Previews::class, ['application' => $this->application->fresh()])
        ->assertDontSee('PR deployment access');
})->with([['dockerimage', null], ['dockerfile', 'FROM nginx']]);

it('denies preview setting changes without application update permission', function (string $role, bool $otherTeam) {
    $user = User::factory()->create();
    $team = $otherTeam ? Team::factory()->create() : $this->team;
    $team->members()->attach($user->id, ['role' => $role]);
    $this->actingAs($user);
    session(['currentTeam' => $team]);

    Livewire::test(Previews::class, ['application' => $this->application])
        ->set('isPreviewDeploymentsEnabled', true)
        ->set('isPrDeploymentsPublicEnabled', true)
        ->call('savePreviewSettings')
        ->assertForbidden();

    expect($this->application->fresh()->settings)
        ->is_preview_deployments_enabled->toBeFalse()
        ->is_pr_deployments_public_enabled->toBeFalse();
})->with([['member', false], ['owner', true]]);

it('saves the docker tag of the remaining preview after another preview is deleted', function () {
    $this->application->update(['build_pack' => 'dockerimage']);
    $previewA = ApplicationPreview::create(['application_id' => $this->application->id, 'pull_request_id' => 2, 'pull_request_html_url' => '', 'docker_registry_image_tag' => 'tag-a']);
    $previewB = ApplicationPreview::create(['application_id' => $this->application->id, 'pull_request_id' => 1, 'pull_request_html_url' => '', 'docker_registry_image_tag' => 'tag-b']);
    Queue::fake();

    Livewire::test(Previews::class, ['application' => $this->application->fresh()])
        ->call('delete', 2)
        ->call('save_preview', $previewB->id)
        ->assertHasNoErrors();

    expect($previewB->fresh()->docker_registry_image_tag)->toBe('tag-b');
});

it('saves the preview deployments limit and clears it when the field is empty', function () {
    Livewire::test(Previews::class, ['application' => $this->application])
        ->assertSee('Preview deployments limit')
        ->assertSet('previewDeploymentsLimit', null)
        ->set('previewDeploymentsLimit', '3')
        ->call('savePreviewDeploymentsLimit')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    expect($this->application->fresh()->settings->preview_deployments_limit)->toBe(3);

    Livewire::test(Previews::class, ['application' => $this->application->fresh()])
        ->assertSet('previewDeploymentsLimit', '3')
        ->set('previewDeploymentsLimit', '')
        ->call('savePreviewDeploymentsLimit')
        ->assertHasNoErrors();

    expect($this->application->fresh()->settings->preview_deployments_limit)->toBeNull();
});

it('shows the preview deployments limit field once for git and docker image applications', function (string $buildPack) {
    $this->application->update(['build_pack' => $buildPack]);
    $application = $this->application->fresh();

    $html = Livewire::test(Previews::class, ['application' => $application])->html();

    expect(substr_count($html, 'wire:change="savePreviewDeploymentsLimit"'))->toBe(1);
    expect($application->git_based())->toBe($buildPack !== 'dockerimage');
})->with(['dockerimage', 'nixpacks']);

it('saves the preview deployments limit for a docker image application', function () {
    $this->application->update(['build_pack' => 'dockerimage', 'docker_registry_image_name' => 'nginx']);

    Livewire::test(Previews::class, ['application' => $this->application->fresh()])
        ->set('previewDeploymentsLimit', '2')
        ->call('savePreviewDeploymentsLimit')
        ->assertHasNoErrors();

    expect($this->application->fresh()->settings->preview_deployments_limit)->toBe(2);
});

it('validates the preview deployments limit', function (string $value) {
    Livewire::test(Previews::class, ['application' => $this->application])
        ->set('previewDeploymentsLimit', $value)
        ->call('savePreviewDeploymentsLimit')
        ->assertHasErrors('previewDeploymentsLimit');

    expect($this->application->fresh()->settings->preview_deployments_limit)->toBeNull();
})->with([
    'zero' => '0',
    'negative' => '-1',
    'above maximum' => '1001',
    'not a number' => 'abc',
]);

it('does not let a team member change the preview deployments limit', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);
    $this->actingAs($member);

    Livewire::test(Previews::class, ['application' => $this->application])
        ->set('previewDeploymentsLimit', '2')
        ->call('savePreviewDeploymentsLimit');

    expect($this->application->fresh()->settings->preview_deployments_limit)->toBeNull();
});

it('does not add a preview from the UI when the preview deployments limit is reached', function () {
    $this->application->update(['build_pack' => 'dockerimage']);
    $this->application->settings()->update(['preview_deployments_limit' => 1]);
    ApplicationPreview::create(['application_id' => $this->application->id, 'pull_request_id' => 1, 'pull_request_html_url' => '', 'docker_registry_image_tag' => 'tag-a']);
    Queue::fake();

    Livewire::test(Previews::class, ['application' => $this->application->fresh()])
        ->call('add', 42, null, 'pr-42')
        ->assertDispatched('error')
        ->call('add_and_deploy', 42, null, 'pr-42')
        ->assertDispatched('error');

    expect(ApplicationPreview::where('application_id', $this->application->id)->where('pull_request_id', 42)->exists())->toBeFalse();
});
