<?php

use App\Models\State;
use App\Models\Suburb;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->vic = State::factory()->create(['code' => 'VIC', 'name' => 'Victoria']);
});

/**
 * Build a real .xlsx upload from rows (first row is the header).
 *
 * @param  list<list<string|int|float|null>>  $rows
 */
function suburbSheet(array $rows, string $name = 'suburbs.xlsx'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'sub');
    $writer = new Writer;
    $writer->openToFile($path);

    foreach ($rows as $row) {
        $writer->addRow(Row::fromValues($row));
    }

    $writer->close();

    return new UploadedFile($path, $name, null, null, true);
}

function importSuburbs(mixed $test, UploadedFile $file, bool $dryRun = false): TestResponse
{
    return $test->actingAs(actingSuperAdmin())->post(route('admin.locations.suburbs.import.store'), [
        'file' => $file,
        'dry_run' => $dryRun,
    ]);
}

const SUBURB_HEADER = ['Suburb', 'Postcode', 'State', 'Country'];

test('an xlsx in the Suburb/Postcode/State/Country format creates suburbs without coordinates', function () {
    $response = importSuburbs($this, suburbSheet([
        SUBURB_HEADER,
        ['Abbotsford', 3067, 'Victoria', 'Australia'],
        ['Altona Meadows', '3028', 'VIC', 'Australia'],
    ]));

    $response->assertOk()->assertInertia(fn ($page) => $page
        ->component('locations/suburbs/import')
        ->where('result.rowsProcessed', 2)
        ->where('result.created', 2)
        ->where('result.updated', 0)
        ->where('result.errors', [])
    );

    $suburb = Suburb::where('name', 'Abbotsford')->sole();
    expect($suburb->state_id)->toBe($this->vic->id)
        ->and($suburb->postcode)->toBe('3067')
        ->and($suburb->lat)->toBeNull()
        ->and($suburb->lng)->toBeNull();
    $this->assertDatabaseHas('suburbs', ['name' => 'Altona Meadows', 'postcode' => '3028']);
});

test('re-importing updates existing suburbs instead of duplicating them', function () {
    Suburb::factory()->create([
        'name' => 'abbotsford', 'state_id' => $this->vic->id, 'postcode' => '3067', 'lat' => -37.8, 'lng' => 144.99,
    ]);

    $response = importSuburbs($this, suburbSheet([
        SUBURB_HEADER,
        ['Abbotsford', 3067, 'Victoria', 'Australia'],
        ['Aberfeldie', 3040, 'Victoria', 'Australia'],
    ]));

    $response->assertInertia(fn ($page) => $page
        ->where('result.created', 1)
        ->where('result.updated', 1)
    );
    expect(Suburb::count())->toBe(2);

    $abbotsford = Suburb::where('postcode', '3067')->sole();
    // Name casing is updated; blank coordinate cells never erase saved ones.
    expect($abbotsford->name)->toBe('Abbotsford')
        ->and((float) $abbotsford->lat)->toBe(-37.8);

    $second = importSuburbs($this, suburbSheet([
        SUBURB_HEADER,
        ['Abbotsford', 3067, 'Victoria', 'Australia'],
    ]));
    $second->assertInertia(fn ($page) => $page->where('result.unchanged', 1)->where('result.updated', 0));
});

test('optional Latitude and Longitude columns are applied to new and existing suburbs', function () {
    Suburb::factory()->create([
        'name' => 'Albion', 'state_id' => $this->vic->id, 'postcode' => '3020', 'lat' => null, 'lng' => null,
    ]);

    importSuburbs($this, suburbSheet([
        [...SUBURB_HEADER, 'Latitude', 'Longitude'],
        ['Albion', 3020, 'Victoria', 'Australia', -37.7767, 144.8186],
        ['Altona', 3018, 'Victoria', 'Australia', -37.8676, 144.8296],
    ]))->assertInertia(fn ($page) => $page->where('result.created', 1)->where('result.updated', 1));

    expect((float) Suburb::where('name', 'Albion')->sole()->lat)->toBe(-37.7767)
        ->and((float) Suburb::where('name', 'Altona')->sole()->lng)->toBe(144.8296);
});

test('a short numeric postcode is zero-padded', function () {
    $nt = State::factory()->create(['code' => 'NT', 'name' => 'Northern Territory']);

    importSuburbs($this, suburbSheet([SUBURB_HEADER, ['Darwin', 800, 'NT', 'Australia']]));

    $this->assertDatabaseHas('suburbs', ['name' => 'Darwin', 'state_id' => $nt->id, 'postcode' => '0800']);
});

test('bad rows are reported per row and do not stop the good rows', function () {
    $response = importSuburbs($this, suburbSheet([
        SUBURB_HEADER,
        ['Abbotsford', 3067, 'Victoria', 'Australia'],
        ['Nowhere', 3000, 'Atlantis', 'Australia'],
        ['Badcode', 'abc', 'Victoria', 'Australia'],
        ['Auckland', 1010, 'Victoria', 'New Zealand'],
        [null, null, null, null],
        ['Altona', 3018, 'Victoria', 'Australia'],
    ]));

    $response->assertInertia(fn ($page) => $page
        ->where('result.rowsProcessed', 5)
        ->where('result.created', 2)
        ->has('result.errors', 3)
        ->where('result.errors.0.row', 3)
        ->where('result.errors.1.row', 4)
        ->where('result.errors.2.row', 5)
    );
    expect(Suburb::count())->toBe(2);
});

test('latitude without longitude is a row error', function () {
    importSuburbs($this, suburbSheet([
        [...SUBURB_HEADER, 'Latitude', 'Longitude'],
        ['Albion', 3020, 'Victoria', 'Australia', -37.7, null],
    ]))->assertInertia(fn ($page) => $page->where('result.created', 0)->has('result.errors', 1));
});

test('a dry run reports what would change but saves nothing', function () {
    importSuburbs($this, suburbSheet([
        SUBURB_HEADER,
        ['Abbotsford', 3067, 'Victoria', 'Australia'],
        ['Abbotsford', 3067, 'Victoria', 'Australia'],
    ]), dryRun: true)->assertInertia(fn ($page) => $page
        ->where('result.dryRun', true)
        ->where('result.created', 1)
        ->where('result.unchanged', 1)
    );

    expect(Suburb::count())->toBe(0);
});

test('a csv upload works too', function () {
    $file = UploadedFile::fake()->createWithContent('suburbs.csv', "Suburb,Postcode,State,Country\nAlbion,3020,Victoria,Australia\n");

    importSuburbs($this, $file)->assertInertia(fn ($page) => $page->where('result.created', 1));
});

test('a file missing a required column is rejected', function () {
    importSuburbs($this, suburbSheet([['Suburb', 'Postcode'], ['Albion', 3020]]))
        ->assertSessionHasErrors('file');

    expect(Suburb::count())->toBe(0);
});

test('a non-spreadsheet upload is rejected', function () {
    importSuburbs($this, UploadedFile::fake()->create('suburbs.pdf', 10))
        ->assertSessionHasErrors('file');
});

test('a corrupt xlsx is rejected with a file error rather than a server error', function () {
    importSuburbs($this, UploadedFile::fake()->createWithContent('suburbs.xlsx', 'not a real workbook'))
        ->assertSessionHasErrors('file');
});

test('users without locations.manage cannot open or use the import', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)->get(route('admin.locations.suburbs.import.index'))->assertForbidden();
    $this->actingAs($user)->post(route('admin.locations.suburbs.import.store'), [
        'file' => suburbSheet([SUBURB_HEADER, ['Albion', 3020, 'Victoria', 'Australia']]),
    ])->assertForbidden();

    expect(Suburb::count())->toBe(0);
});

test('a super_admin can open the import page', function () {
    $this->actingAs(actingSuperAdmin())->get(route('admin.locations.suburbs.import.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('locations/suburbs/import'));
});
