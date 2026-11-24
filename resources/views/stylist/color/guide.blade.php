@extends('stylist.color.layout')

@section('title', 'How to label')

@section('content')
    <div class="card prose">
        {{-- Rendered from docs/stylist-colour-labelling.md. One copy of the
             text: a page and a document that drift apart are worse than either
             alone, and the repository copy is the one a reviewer reads. --}}
        {!! $body !!}
    </div>

    <p><a class="button ghost" href="{{ route('stylist.color.index') }}">Back to the queue</a></p>
@endsection
