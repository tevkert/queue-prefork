<x-mail::message>
# Your {{ $version }} digest, {{ $user->name }}

@foreach ($posts as $post)
## {{ $post->title }}

*{{ $post->source?->name }}* · {{ $post->published_at?->format('M j, H:i') }}

{{ \Illuminate\Support\Str::limit($post->excerpt, 200) }}

@if (! empty($post->tags))
<x-mail::panel>
{{ implode(' · ', $post->tags) }}
</x-mail::panel>
@endif
@endforeach

<x-mail::button :url="url('/digest')">
Open the feed
</x-mail::button>

<x-mail::table>
| Source | Posts |
|:--------|------:|
@foreach ($posts->groupBy(fn ($post) => $post->source?->name) as $name => $group)
| {{ $name }} | {{ $group->count() }} |
@endforeach
</x-mail::table>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
