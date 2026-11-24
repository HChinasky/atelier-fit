{{-- A capsule's cover: a photo across the whole card (Figma 1907:9446), kept
     sharp — the frame's blur read as a broken image. The occasion's own photo when one has been
     put in public/images/capsules/<occasion>.jpg, otherwise the shared cover
     from the frame (cover.jpg); only with neither the capsule's first look
     laid out piece by piece, the flat shots' white grounds on the grey.
     Expects: $occasion, $set (CapsuleSet). --}}
@php($coverPhoto = collect(["images/capsules/{$occasion}.jpg", 'images/capsules/cover.jpg'])
    ->first(fn (string $path) => file_exists(public_path($path))))
<div class="capsule-cover">
    @if ($coverPhoto)
        <img class="cover-photo" src="{{ asset($coverPhoto) }}" alt="" loading="lazy">
    @elseif (! $set->isEmpty())
        <div class="cover-strip">
            @foreach ($set->capsules[0]->pieces as $piece)
                <img src="{{ $piece->image_flat_url }}" alt="" loading="lazy">
            @endforeach
        </div>
    @endif
</div>
