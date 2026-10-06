<?php

use App\Enums\MediaStatus;
use App\Enums\Status;
use App\Jobs\LocalizeModelImagesJob;
use App\Jobs\ProcessMediaJob;
use App\Models\Brand;
use App\Models\Media;
use App\Models\TyreModel;
use App\Models\User;
use App\Services\Media\ImageConverter;
use App\Services\Media\MediaLibrary;
use App\Services\Products\CatalogImportService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('public');
    Storage::fake('local');
});

function pngBinary(int $width = 2400, int $height = 1200): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 200, 30, 30));
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

function mediaManager(): User
{
    $user = User::factory()->withTwoFactor()->create();
    $user->assignRole('super_admin');

    return $user;
}

function modelWithImages(array $images): TyreModel
{
    return TyreModel::factory()->for(Brand::factory())->create(['status' => Status::Active, 'images' => $images]);
}

test('the converter outputs WebP, capped in size, plus a thumbnail', function () {
    $result = (new ImageConverter)->toWebp(pngBinary());

    expect(substr($result['main'], 8, 4))->toBe('WEBP')
        ->and(substr($result['thumb'], 8, 4))->toBe('WEBP')
        ->and($result['width'])->toBe(ImageConverter::MAX_DIMENSION)
        ->and($result['height'])->toBe(800);

    [$thumbWidth] = getimagesizefromstring($result['thumb']);
    expect($thumbWidth)->toBe(ImageConverter::THUMB_DIMENSION);
});

test('an upload is queued, then converted to WebP by the job', function () {
    Queue::fake();
    $admin = mediaManager();

    $this->actingAs($admin)->post(route('admin.media.store'), [
        'files' => [UploadedFile::fake()->createWithContent('tyre.png', pngBinary(300, 300))],
    ])->assertRedirect();

    $media = Media::query()->sole();
    expect($media->status)->toBe(MediaStatus::Pending)
        ->and($media->original_name)->toBe('tyre.png')
        ->and($media->uploaded_by)->toBe($admin->id);
    Queue::assertPushed(ProcessMediaJob::class, fn ($job) => $job->mediaId === $media->id);
    Storage::disk('local')->assertExists($media->incoming_path);

    (new ProcessMediaJob($media->id))->handle(app(MediaLibrary::class));

    $media->refresh();
    expect($media->status)->toBe(MediaStatus::Ready)
        ->and($media->mime)->toBe('image/webp')
        ->and($media->incoming_path)->toBeNull();
    Storage::disk('public')->assertExists([$media->path, $media->thumb_path]);
    expect(str_ends_with($media->path, '.webp'))->toBeTrue();
});

test('a corrupt upload ends up failed with the error recorded and can be retried', function () {
    Queue::fake();
    $media = Media::factory()->create(['incoming_path' => 'media-incoming/bad.png']);
    Storage::disk('local')->put('media-incoming/bad.png', 'not an image');

    expect(fn () => (new ProcessMediaJob($media->id))->handle(app(MediaLibrary::class)))->toThrow(RuntimeException::class);

    $media->refresh();
    expect($media->status)->toBe(MediaStatus::Failed)->and($media->error)->not->toBeEmpty();

    $this->actingAs(mediaManager())->post(route('admin.media.retry', $media))->assertRedirect();

    expect($media->refresh()->status)->toBe(MediaStatus::Pending);
    Queue::assertPushed(ProcessMediaJob::class);
});

test('uploads reject non-images and users without content.manage', function () {
    $this->actingAs(mediaManager())->post(route('admin.media.store'), [
        'files' => [UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')],
    ])->assertSessionHasErrors('files.0');

    $viewer = User::factory()->withTwoFactor()->create();
    $this->actingAs($viewer)->get(route('admin.media.index'))->assertForbidden();
});

test('remote images are downloaded once, converted, and the model URLs are rewritten', function () {
    Http::fake(['https://old.example/*' => Http::response(pngBinary(500, 500), 200, ['Content-Type' => 'image/png'])]);

    $shared = 'https://old.example/uploads/zeta.jpg';
    $first = modelWithImages([$shared, '/tyres/placeholder-tyre.svg']);
    $second = modelWithImages([$shared]);

    (new LocalizeModelImagesJob($first->id))->handle(app(MediaLibrary::class));
    (new LocalizeModelImagesJob($second->id))->handle(app(MediaLibrary::class));

    $media = Media::query()->sole();
    expect($media->status)->toBe(MediaStatus::Ready)->and($media->source_url)->toBe($shared);
    Http::assertSentCount(1);

    $first->refresh();
    $second->refresh();
    expect($first->images)->toBe([$media->url(), '/tyres/placeholder-tyre.svg'])
        ->and($second->images)->toBe([$media->url()])
        ->and(Media::isLocalUrl($media->url()))->toBeTrue();
});

test('a failed download keeps the original URL and records the failure', function () {
    Http::fake(['*' => Http::response('nope', 404)]);
    $model = modelWithImages(['https://old.example/missing.jpg']);

    (new LocalizeModelImagesJob($model->id))->handle(app(MediaLibrary::class));

    expect($model->refresh()->images)->toBe(['https://old.example/missing.jpg'])
        ->and(Media::query()->sole()->status)->toBe(MediaStatus::Failed);
});

test('a URL that returns HTML instead of an image is rejected', function () {
    Http::fake(['*' => Http::response('<html></html>', 200, ['Content-Type' => 'text/html'])]);
    $model = modelWithImages(['https://old.example/page']);

    (new LocalizeModelImagesJob($model->id))->handle(app(MediaLibrary::class));

    expect(Media::query()->sole()->status)->toBe(MediaStatus::Failed);
});

test('private and non-http addresses are never fetched', function () {
    config(['media.block_private_hosts' => true]);
    Http::fake();
    $model = modelWithImages(['http://127.0.0.1/secret.png', 'file:///etc/passwd']);

    (new LocalizeModelImagesJob($model->id))->handle(app(MediaLibrary::class));

    Http::assertNothingSent();
    expect($model->refresh()->images)->toBe(['http://127.0.0.1/secret.png', 'file:///etc/passwd'])
        ->and(Media::query()->where('status', MediaStatus::Failed)->count())->toBe(2);
});

test('media:localize-images queues only models that still have remote images', function () {
    Queue::fake();
    $remote = modelWithImages(['https://old.example/a.jpg']);
    modelWithImages(['/tyres/placeholder-tyre.svg']);
    modelWithImages([]);

    $this->artisan('media:localize-images')->assertSuccessful();

    Queue::assertPushed(LocalizeModelImagesJob::class, 1);
    Queue::assertPushed(LocalizeModelImagesJob::class, fn ($job) => $job->tyreModelId === $remote->id);
});

test('a real catalog import queues image downloads, a dry run does not', function () {
    config(['media.localize_on_import' => true]);
    Queue::fake();

    $row = [
        'Name' => 'Zeta Alventi - ZT1', 'Published' => '1', 'Regular price' => '105.00', 'Brands' => 'ZETA',
        'Images' => 'https://old.example/zeta.jpg',
        'Attribute 1 name' => 'Product IP', 'Attribute 1 value(s)' => 'ZT1',
        'Attribute 2 name' => 'Pattern', 'Attribute 2 value(s)' => 'ALVENTI',
        'Attribute 3 name' => 'Width', 'Attribute 3 value(s)' => '195',
        'Attribute 4 name' => 'Profile', 'Attribute 4 value(s)' => '65',
        'Attribute 5 name' => 'Diameter', 'Attribute 5 value(s)' => '15',
        'Attribute 6 name' => 'Load Index', 'Attribute 6 value(s)' => '95',
        'Attribute 7 name' => 'Speed Rating', 'Attribute 7 value(s)' => 'H',
    ];

    app(CatalogImportService::class)->import([$row], dryRun: true);
    Queue::assertNothingPushed();

    app(CatalogImportService::class)->import([$row]);
    Queue::assertPushed(LocalizeModelImagesJob::class, 1);
});

test('the media manager lists, searches and filters, and refuses to delete a used image', function () {
    $admin = mediaManager();
    $used = Media::factory()->ready()->create(['original_name' => 'used-hero.jpg']);
    Media::factory()->failed()->create(['original_name' => 'broken.jpg']);
    modelWithImages([$used->url()]);

    $this->actingAs($admin)->get(route('admin.media.index'))
        ->assertInertia(fn (Assert $page) => $page->component('media/index')->has('media.data', 2)->where('counts.failed', 1));

    $this->actingAs($admin)->get(route('admin.media.index', ['search' => 'broken']))
        ->assertInertia(fn (Assert $page) => $page->has('media.data', 1));

    $this->actingAs($admin)->get(route('admin.media.index', ['status' => 'ready']))
        ->assertInertia(fn (Assert $page) => $page->has('media.data', 1)->where('media.data.0.used_by', 1));

    $this->actingAs($admin)->delete(route('admin.media.destroy', $used))->assertRedirect();
    expect(Media::query()->whereKey($used->id)->exists())->toBeTrue();

    $unused = Media::factory()->ready()->create();
    Storage::disk('public')->put($unused->path, 'x');
    $this->actingAs($admin)->delete(route('admin.media.destroy', $unused))->assertRedirect();
    expect(Media::query()->whereKey($unused->id)->exists())->toBeFalse();
    Storage::disk('public')->assertMissing($unused->path);
});
