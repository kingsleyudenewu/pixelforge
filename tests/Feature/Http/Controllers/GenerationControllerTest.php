<?php

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGenerationToPixelforge;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

describe('store', function () {
    test('queues a generation and holds its quoted maximum from the balance', function () {
        Queue::fake([SubmitGenerationToPixelforge::class]);
        $user = User::factory()->withCredits('1.00')->create();

        $response = $this->actingAs($user)->post(route('generations.store'), [
            'prompt' => 'A lighthouse at dawn',
            'size' => '1024x1024',
            'quality' => 'medium',
        ]);

        // 20 characters ≈ 5 text tokens (4–6), 1,100 output tokens and 200–2,000 reasoning tokens, plus 25%.
        $generation = Generation::sole();
        $response->assertRedirect(route('dashboard'))
            ->assertInertiaFlash('toast.message', 'Generation queued. Up to $0.0863 is reserved until it finishes.');
        expect($generation)
            ->status->toBe(GenerationStatus::Queued)
            ->estimated_charge_min_nanos->toBe(58_150_000)
            ->estimated_charge_max_nanos->toBe(86_287_500);
        expect($user->refresh()->credit_balance_nanos)->toBe(1_000_000_000 - 86_287_500);
        Queue::assertPushed(SubmitGenerationToPixelforge::class, fn (SubmitGenerationToPixelforge $job) => $job->generation->is($generation));
    });

    test('stores exactly the fields Pixelforge accepts, tagged with our ids', function () {
        Queue::fake([SubmitGenerationToPixelforge::class]);
        $user = User::factory()->withCredits('1.00')->create();

        $this->actingAs($user)->post(route('generations.store'), [
            'prompt' => 'A lighthouse at dawn',
            'size' => '1536x1024',
            'quality' => 'low',
            'reference_images' => ['https://example.com/a.png', 'https://example.com/b.png'],
        ]);

        $generation = Generation::sole();
        expect($generation->request_payload)->toBe([
            'prompt' => 'A lighthouse at dawn',
            'size' => '1536x1024',
            'quality' => 'low',
            'reference_images' => ['https://example.com/a.png', 'https://example.com/b.png'],
            'metadata' => [
                'app_generation_id' => $generation->id,
                'app_user_id' => (string) $user->id,
                'app_env' => 'testing',
            ],
        ]);
    });

    test('blocks a generation the user cannot afford', function () {
        Queue::fake([SubmitGenerationToPixelforge::class]);
        $user = User::factory()->withCredits('0.05')->create();

        $response = $this->actingAs($user)->post(route('generations.store'), [
            'prompt' => 'A lighthouse at dawn',
            'size' => '1024x1024',
            'quality' => 'medium',
        ]);

        $response->assertSessionHasErrors(['credits' => "You don't have enough credits for this generation. Buy credits to continue."]);
        $this->assertDatabaseCount('generations', 0);
        expect($user->refresh()->credit_balance_nanos)->toBe(50_000_000);
        Queue::assertNotPushed(SubmitGenerationToPixelforge::class);
    });

    test('limits how many generations one user can have in progress', function () {
        Queue::fake([SubmitGenerationToPixelforge::class]);
        $user = User::factory()->withCredits('5.00')->create();
        Generation::factory()->count(5)->for($user)->create();

        $response = $this->actingAs($user)->post(route('generations.store'), [
            'prompt' => 'A lighthouse at dawn',
            'size' => '1024x1024',
            'quality' => 'medium',
        ]);

        $response->assertSessionHasErrors('generation');
        $this->assertDatabaseCount('generations', 5);
        Queue::assertNotPushed(SubmitGenerationToPixelforge::class);
    });

    test('rejects invalid generation settings', function (array $input, string $field, string $message) {
        Queue::fake([SubmitGenerationToPixelforge::class]);
        $user = User::factory()->withCredits('1.00')->create();

        $response = $this->actingAs($user)->post(route('generations.store'), [
            'prompt' => 'A lighthouse at dawn',
            'size' => '1024x1024',
            'quality' => 'medium',
            ...$input,
        ]);

        $response->assertSessionHasErrors([$field => $message]);
        $this->assertDatabaseCount('generations', 0);
    })->with([
        'missing prompt' => [['prompt' => ''], 'prompt', 'The prompt field is required.'],
        'prompt over 4,000 characters' => [['prompt' => str_repeat('a', 4001)], 'prompt', 'The prompt field must not be greater than 4000 characters.'],
        'unsupported size' => [['size' => '512x512'], 'size', 'The selected size is invalid.'],
        'unsupported quality' => [['quality' => 'ultra'], 'quality', 'The selected quality is invalid.'],
        'more than four references' => [['reference_images' => array_fill(0, 5, 'https://example.com/a.png')], 'reference_images', 'You can add up to 4 reference images.'],
        'non-http reference' => [['reference_images' => ['ftp://example.com/a.png']], 'reference_images.0', 'Reference images must be http or https URLs.'],
    ]);

    test('redirects guests to the login page', function () {
        $this->post(route('generations.store'), ['prompt' => 'A lighthouse at dawn'])->assertRedirect(route('login'));

        $this->assertDatabaseCount('generations', 0);
    });
});

describe('index', function () {
    test('shows only the signed-in user\'s generations, newest first', function () {
        $user = User::factory()->create();
        $older = Generation::factory()->for($user)->succeeded()->create(['created_at' => now()->subHour()]);
        $newer = Generation::factory()->for($user)->failed()->create();
        Generation::factory()->succeeded()->create();

        $response = $this->actingAs($user)->get(route('generations.index'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('generations/Index')
            ->has('generations.data', 2)
            ->where('generations.data.0.id', $newer->id)
            ->where('generations.data.0.charge', '0.000000000')
            ->where('generations.data.1.id', $older->id)
            ->where('generations.data.1.charge', '0.070687500'));
    });

    test('hides image links once Pixelforge has expired them', function () {
        $user = User::factory()->create();
        Generation::factory()->for($user)->succeeded()->create(['image_url_expires_at' => now()->subMinute()]);

        $response = $this->actingAs($user)->get(route('generations.index'));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('generations.data.0.image_url', null)
            ->where('generations.data.0.image_expired', true));
    });
});

describe('create', function () {
    test('provides Pixelforge pricing so the page can estimate costs before submitting', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('generations/Create')
            ->where('pricing.rates.output_tokens', '40.00')
            ->where('pricing.output_tokens.high.1536x1024', 6450)
            ->where('markupBasisPoints', 2500));
    });
});
