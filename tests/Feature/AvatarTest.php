<?php

use App\Models\User;
use App\Services\Privacy\PersonalDataEraser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * The member's profile picture: uploaded on the profile page, stored privately
 * under its own prefix, served back only to its owner, erased with the account.
 */

beforeEach(function () {
    config(['features.membership' => true]);
    Storage::fake('r2');
});

function avatarMember(): User
{
    return User::factory()->emailOnly()->onboarded()->create();
}

it('stores an uploaded picture as a square JPEG under the avatar prefix', function () {
    $user = avatarMember();

    $this->actingAs($user)
        ->from('/account/settings')
        ->post('/account/avatar', ['avatar' => UploadedFile::fake()->image('me.png', 900, 600)])
        ->assertRedirect('/account/settings')
        ->assertSessionHas('avatar_status');

    $key = $user->fresh()->avatar_path;
    expect($key)->toStartWith('avatars/'.$user->id.'/')->toEndWith('.jpg');
    Storage::disk('r2')->assertExists($key);

    [$width, $height, $type] = getimagesizefromstring(Storage::disk('r2')->get($key));
    expect([$width, $height, $type])->toBe([512, 512, IMAGETYPE_JPEG]);
});

it('drops the previous picture when a new one replaces it', function () {
    $user = avatarMember();
    $this->actingAs($user)->post('/account/avatar', ['avatar' => UploadedFile::fake()->image('a.jpg', 300, 300)]);
    $old = $user->fresh()->avatar_path;

    $this->actingAs($user)->post('/account/avatar', ['avatar' => UploadedFile::fake()->image('b.jpg', 300, 300)]);
    $new = $user->fresh()->avatar_path;

    expect($new)->not->toBe($old);
    Storage::disk('r2')->assertMissing($old);
    Storage::disk('r2')->assertExists($new);
});

it('refuses a file that is not a real JPG, PNG or WebP', function (UploadedFile $file) {
    $user = avatarMember();

    $this->actingAs($user)
        ->post('/account/avatar', ['avatar' => $file])
        ->assertSessionHasErrors('avatar');

    expect($user->fresh()->avatar_path)->toBeNull();
    expect(Storage::disk('r2')->allFiles())->toBeEmpty();
})->with([
    'text renamed to .jpg' => fn () => UploadedFile::fake()->createWithContent('me.jpg', 'not an image'),
    'svg' => fn () => UploadedFile::fake()->createWithContent('me.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>'),
    'too large' => fn () => UploadedFile::fake()->image('big.jpg')->size(9000),
]);

it('serves the picture to its owner only, privately cached', function () {
    $user = User::factory()->emailOnly()->onboarded()->withAvatar()->create();
    Storage::disk('r2')->put($user->avatar_path, 'jpeg-bytes');

    $this->actingAs($user)->get('/account/avatar')
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg')
        ->assertHeader('Cache-Control', 'max-age=86400, private');

    // Another member hits the same URL and gets nothing of the first one's.
    $this->actingAs(avatarMember())->get('/account/avatar')->assertNotFound();
});

it('keeps the avatar routes behind sign-in', function () {
    $this->get('/account/avatar')->assertRedirect(route('login'));
    $this->post('/account/avatar')->assertRedirect(route('login'));
    $this->delete('/account/avatar')->assertRedirect(route('login'));
});

it('removes the picture and its stored object', function () {
    $user = User::factory()->emailOnly()->onboarded()->withAvatar()->create();
    Storage::disk('r2')->put($user->avatar_path, 'jpeg-bytes');
    $key = $user->avatar_path;

    $this->actingAs($user)->delete('/account/avatar')->assertRedirect();

    expect($user->fresh()->avatar_path)->toBeNull();
    Storage::disk('r2')->assertMissing($key);
});

it('draws the picture in the sidebar and the top bar once set, the placeholder otherwise', function () {
    $user = User::factory()->emailOnly()->onboarded()->withAvatar()->create();
    $url = route('membership.account.avatar', ['v' => substr(md5($user->avatar_path), 0, 10)]);

    $this->actingAs($user)->get('/account')
        ->assertSee('<span class="avatar"><img src="'.e($url).'" alt=""></span>', escape: false)
        ->assertSee('aria-label="Профіль"><img src="'.e($url).'" alt=""></a>', escape: false);

    $this->actingAs(avatarMember())->get('/account')
        ->assertSee('<span class="avatar"></span>', escape: false)
        ->assertDontSee(route('membership.account.avatar'));
});

it('offers upload on the profile page and says honestly how long the picture is kept', function () {
    $this->actingAs(avatarMember())->get('/account/settings')
        ->assertOk()
        ->assertSee('id="avatar-card"', escape: false)
        ->assertSee('action="'.route('membership.account.avatar.update').'" enctype="multipart/form-data"', escape: false)
        ->assertSee('Завантажити фото')
        ->assertDontSee('id="avatar-remove-form"', escape: false)
        ->assertSee('для примірок не використовується й зберігається, доки ти його не видалиш або не видалиш акаунт');

    $user = User::factory()->emailOnly()->onboarded()->withAvatar()->create();
    $this->actingAs($user)->get('/account/settings')
        ->assertSee('Змінити фото')
        ->assertSee('id="avatar-remove-form"', escape: false);
});

it('erases the picture with the account', function () {
    $user = User::factory()->emailOnly()->withAvatar()->create();
    Storage::disk('r2')->put($user->avatar_path, 'jpeg-bytes');
    $key = $user->avatar_path;

    app(PersonalDataEraser::class)->eraseUser($user);

    Storage::disk('r2')->assertMissing($key);
    expect(User::find($user->id))->toBeNull();
});
