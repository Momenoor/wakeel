<?php

namespace Tests\Feature;

use App\Enums\SignatureStampKind;
use App\Filament\Forms\Components\SignaturePad;
use App\Filament\Mms\Resources\SignatureStamps\Pages\CreateSignatureStamp;
use App\Filament\Mms\Resources\SignatureStamps\Pages\EditSignatureStamp;
use App\Filament\Mms\Resources\SignatureStamps\Pages\ListSignatureStamps;
use App\Filament\Mms\Resources\SignatureStamps\SignatureStampResource;
use App\Models\Letterhead;
use App\Models\SignatureStamp;
use App\Models\User;
use App\Services\SignatureStamps\LetterheadImport;
use App\Support\ScreenPermissions;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The library of signatures and stamps: each person's own stays theirs (neither listed nor its image sent
 * to anyone else), the office's are for those allowed, and only those who manage them may share or change
 * them. Images — uploaded or drawn — are kept on the private disk.
 */
class SignatureStampsTest extends TestCase
{
    use RefreshDatabase;

    /** A 1×1 transparent PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');
        Filament::setCurrentPanel('mms');
    }

    /** @param  list<string>  $permissions */
    private function userWith(array $permissions = [ScreenPermissions::SIGN_PDF]): User
    {
        $user = User::factory()->create();

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $user->givePermissionTo($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    /** @param  array<string, mixed>  $attributes */
    private function stamp(User $owner, array $attributes = []): SignatureStamp
    {
        return SignatureStamp::create([
            'name' => 'Signature of '.$owner->name,
            'kind' => SignatureStampKind::SIGNATURE,
            'user_id' => $owner->getKey(),
            'is_shared' => false,
            'path' => SignatureStamp::storeImage(base64_decode(self::PNG)),
            'disk' => SignatureStamp::disk(),
            'width_mm' => 40,
            ...$attributes,
        ]);
    }

    public function test_the_library_lists_ones_own_and_the_offices_only_for_those_allowed(): void
    {
        $me = $this->userWith();
        $colleague = $this->userWith();
        $mine = $this->stamp($me);
        $theirs = $this->stamp($colleague);
        $office = $this->stamp($colleague, ['name' => 'Office stamp', 'kind' => SignatureStampKind::STAMP, 'is_shared' => true]);

        $this->actingAs($me);
        Livewire::test(ListSignatureStamps::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs, $office]);

        $this->actingAs($withShared = $this->userWith([ScreenPermissions::SIGN_PDF, ScreenPermissions::SHARED_SIGNATURES]));
        $own = $this->stamp($withShared);
        Livewire::test(ListSignatureStamps::class)
            ->assertCanSeeTableRecords([$own, $office])
            ->assertCanNotSeeTableRecords([$mine, $theirs]);
    }

    public function test_the_library_needs_the_signing_or_the_managing_permission(): void
    {
        $this->actingAs($this->userWith([]));
        $this->assertFalse(SignatureStampResource::canViewAny());

        $this->actingAs($this->userWith([ScreenPermissions::MANAGE_SHARED_SIGNATURES]));
        $this->assertTrue(SignatureStampResource::canViewAny());
    }

    public function test_an_image_is_sent_only_to_whoever_may_use_it(): void
    {
        $me = $this->userWith();
        $colleague = $this->userWith();
        $mine = $this->stamp($me);
        $theirs = $this->stamp($colleague);
        $office = $this->stamp($colleague, ['is_shared' => true]);

        $this->actingAs($me);
        $this->get($mine->imageUrl())->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get($theirs->imageUrl())->assertForbidden();
        $this->get($office->imageUrl())->assertForbidden();

        $this->actingAs($this->userWith([ScreenPermissions::SIGN_PDF, ScreenPermissions::SHARED_SIGNATURES]));
        $this->get($office->imageUrl())->assertOk();
        $this->get($theirs->imageUrl())->assertForbidden();

        // Not even a super admin, who passes every gate, signs with someone else's own signature.
        $super = User::factory()->create()->assignRole(Role::firstOrCreate(['name' => config('filament-shield.super_admin.name'), 'guard_name' => 'web']));
        $this->actingAs($super);
        $this->get($theirs->imageUrl())->assertForbidden();
        Livewire::test(ListSignatureStamps::class)->assertCanNotSeeTableRecords([$theirs]);

        // Deleted: gone for everyone.
        $mine->delete();
        $this->actingAs($me)->get($mine->imageUrl())->assertNotFound();
    }

    public function test_a_drawn_signature_is_kept_as_a_png_on_the_private_disk(): void
    {
        $this->actingAs($me = $this->userWith());

        Livewire::test(CreateSignatureStamp::class)
            ->fillForm([
                'name' => 'My signature',
                'kind' => SignatureStampKind::SIGNATURE->value,
                'width_mm' => 45,
                'image_source' => 'draw',
                'drawing' => 'data:image/png;base64,'.self::PNG,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $stamp = SignatureStamp::query()->sole();
        $this->assertSame([$me->getKey(), false, 45.0, 'attachments'], [$stamp->user_id, $stamp->is_shared, $stamp->width_mm, $stamp->disk]);
        $this->assertMatchesRegularExpression('#^signature-stamps/[0-9a-f-]{36}\.png$#', $stamp->path);
        Storage::disk('attachments')->assertExists($stamp->path);
        $this->assertSame(base64_decode(self::PNG), Storage::disk('attachments')->get($stamp->path));
        // Never on the public disk.
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_a_drawing_that_is_not_a_png_is_refused(): void
    {
        $this->actingAs($this->userWith());

        Livewire::test(CreateSignatureStamp::class)
            ->fillForm(['name' => 'Bad', 'kind' => 'signature', 'width_mm' => 40, 'image_source' => 'draw', 'drawing' => 'data:image/png;base64,'.base64_encode('<svg onload=alert(1)>')])
            ->call('create')
            ->assertHasFormErrors(['drawing']);

        $this->assertNull(SignaturePad::decode('data:image/jpeg;base64,'.self::PNG));
        $this->assertSame(0, SignatureStamp::query()->count());
    }

    public function test_an_uploaded_image_must_be_a_picture_of_at_most_two_megabytes(): void
    {
        $this->actingAs($this->userWith());
        $form = fn (UploadedFile $file): array => ['name' => 'Stamp', 'kind' => 'stamp', 'width_mm' => 35, 'image_source' => 'upload', 'upload' => $file];

        Livewire::test(CreateSignatureStamp::class)
            ->fillForm($form(UploadedFile::fake()->create('stamp.pdf', 10, 'application/pdf')))
            ->call('create')
            ->assertHasFormErrors(['upload']);

        Livewire::test(CreateSignatureStamp::class)
            ->fillForm($form(UploadedFile::fake()->image('stamp.png', 400, 400)->size(3000)))
            ->call('create')
            ->assertHasFormErrors(['upload']);

        $this->assertSame(0, SignatureStamp::query()->count());

        Livewire::test(CreateSignatureStamp::class)
            ->fillForm($form(UploadedFile::fake()->image('stamp.png', 400, 400)))
            ->call('create')
            ->assertHasNoFormErrors();

        $stamp = SignatureStamp::query()->sole();
        $this->assertSame(SignatureStampKind::STAMP, $stamp->kind);
        $this->assertMatchesRegularExpression('#^signature-stamps/[0-9a-f-]{36}\.png$#', $stamp->path);
        Storage::disk('attachments')->assertExists($stamp->path);
    }

    public function test_only_those_who_manage_the_offices_may_share_one_or_change_a_shared_one(): void
    {
        $this->actingAs($signer = $this->userWith([ScreenPermissions::SIGN_PDF, ScreenPermissions::SHARED_SIGNATURES]));

        Livewire::test(CreateSignatureStamp::class)
            ->assertFormFieldHidden('is_shared')
            ->fillForm(['name' => 'Mine', 'kind' => 'signature', 'width_mm' => 40, 'image_source' => 'draw', 'drawing' => 'data:image/png;base64,'.self::PNG, 'is_shared' => true])
            ->call('create')
            ->assertHasNoFormErrors();
        $this->assertFalse(SignatureStamp::query()->sole()->is_shared);

        $office = $this->stamp($this->userWith(), ['is_shared' => true]);
        $this->assertFalse(SignatureStampResource::canEdit($office));
        $this->assertFalse(SignatureStampResource::canDelete($office));
        // Their own, always.
        $this->assertTrue(SignatureStampResource::canEdit(SignatureStamp::query()->where('user_id', $signer->getKey())->sole()));

        $this->actingAs($manager = $this->userWith([ScreenPermissions::SIGN_PDF, ScreenPermissions::SHARED_SIGNATURES, ScreenPermissions::MANAGE_SHARED_SIGNATURES]));
        $this->assertTrue(SignatureStampResource::canEdit($office));

        Livewire::test(CreateSignatureStamp::class)
            ->fillForm(['name' => 'Office', 'kind' => 'stamp', 'width_mm' => 35, 'image_source' => 'draw', 'drawing' => 'data:image/png;base64,'.self::PNG, 'is_shared' => true])
            ->call('create')
            ->assertHasNoFormErrors();
        $this->assertTrue(SignatureStamp::query()->where('user_id', $manager->getKey())->sole()->is_shared);
    }

    public function test_replacing_the_image_removes_the_old_file(): void
    {
        $this->actingAs($me = $this->userWith());
        $stamp = $this->stamp($me);
        $old = $stamp->path;

        Livewire::test(EditSignatureStamp::class, ['record' => $stamp->getRouteKey()])
            ->assertFormSet(['image_source' => 'keep'])
            ->fillForm(['image_source' => 'draw', 'drawing' => 'data:image/png;base64,'.self::PNG])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNotSame($old, $stamp->refresh()->path);
        Storage::disk('attachments')->assertMissing($old);
        Storage::disk('attachments')->assertExists($stamp->path);
    }

    public function test_the_letterheads_signature_and_stamp_are_imported_as_the_offices_once(): void
    {
        Storage::disk('public')->put('letterheads/sign.png', base64_decode(self::PNG));
        Storage::disk('public')->put('letterheads/stamp.png', base64_decode(self::PNG));
        Letterhead::create([
            'name' => 'Main', 'orientation' => 'portrait', 'margin_top' => 45, 'margin_right' => 20, 'margin_bottom' => 30, 'margin_left' => 20,
            'watermark_type' => 'none', 'signature_image' => 'letterheads/sign.png', 'signature_height' => 20, 'stamp_image' => 'letterheads/stamp.png', 'stamp_height' => 30,
        ]);
        $manager = $this->userWith([ScreenPermissions::MANAGE_SHARED_SIGNATURES]);

        $this->assertTrue(LetterheadImport::available());
        $this->assertSame(2, LetterheadImport::run($manager));
        $this->assertSame(0, LetterheadImport::run($manager));

        $stamps = SignatureStamp::query()->orderBy('id')->get();
        $this->assertSame([SignatureStampKind::SIGNATURE, SignatureStampKind::STAMP], $stamps->pluck('kind')->all());
        $this->assertSame([true, true], $stamps->pluck('is_shared')->all());
        // A square picture: as wide as the letterhead draws it tall.
        $this->assertSame([20.0, 30.0], $stamps->pluck('width_mm')->all());
        foreach ($stamps as $stamp) {
            Storage::disk('attachments')->assertExists($stamp->path);
        }
        // The letterhead keeps its own.
        Storage::disk('public')->assertExists('letterheads/sign.png');
    }
}
