<?php

use App\Models\AuditLog;
use App\Models\TyreVariant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function catalogCsv(): UploadedFile
{
    $header = ['Name', 'Published', 'Regular price', 'Brands', 'Images', 'Attribute 1 name', 'Attribute 1 value(s)', 'Attribute 2 name', 'Attribute 2 value(s)', 'Attribute 3 name', 'Attribute 3 value(s)', 'Attribute 4 name', 'Attribute 4 value(s)', 'Attribute 5 name', 'Attribute 5 value(s)', 'Attribute 6 name', 'Attribute 6 value(s)', 'Attribute 7 name', 'Attribute 7 value(s)'];
    $row = ['Zeta Alventi - ZT1', '1', '105.00', 'ZETA', 'https://example.test/a.jpg', 'Product IP', 'ZT1', 'Pattern', 'ALVENTI', 'Width', '195', 'Profile', '65', 'Diameter', '15', 'Load Index', '95', 'Speed Rating', 'H'];
    $bad = ['Broken - X', '1', '', 'ZETA', '', 'Pattern', 'ALVENTI'];

    $path = tempnam(sys_get_temp_dir(), 'imp');
    $handle = fopen($path, 'w');
    foreach ([$header, $row, $bad] as $line) {
        fputcsv($handle, $line);
    }
    fclose($handle);

    return new UploadedFile($path, 'sheet.csv', 'text/csv', null, true);
}

test('a real catalog import writes one audit log row with the summary', function () {
    $admin = actingSuperAdmin();

    $this->actingAs($admin)->post(route('admin.products.import.store'), ['file' => catalogCsv()])->assertOk();

    $log = AuditLog::query()->where('action', 'catalog.imported')->sole();

    expect($log->actor_id)->toBe($admin->id)
        ->and($log->auditable_type)->toBe(TyreVariant::class)
        ->and($log->after['file'])->toBe('sheet.csv')
        ->and($log->after['dry_run'])->toBeFalse()
        ->and($log->after['variants_created'])->toBe(1)
        ->and($log->after['error_count'])->toBeGreaterThan(0)
        ->and($log->after['errors'])->toHaveCount($log->after['error_count']);
});

test('a dry run is logged under its own action', function () {
    $admin = actingSuperAdmin();

    $this->actingAs($admin)->post(route('admin.products.import.store'), ['file' => catalogCsv(), 'dry_run' => '1'])->assertOk();

    expect(AuditLog::query()->where('action', 'catalog.import_dry_run')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'catalog.imported')->count())->toBe(0);
});
