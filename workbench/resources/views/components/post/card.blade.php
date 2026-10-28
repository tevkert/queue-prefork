@props(['post'])

<article {{ $attributes->class(['post-card', 'is-sensitive' => $post->is_sensitive]) }}>
    <header>
        <a href="/s/{{ $post->source?->slug }}" class="source">{{ $post->source?->name }}</a>
        <time datetime="{{ $post->published_at?->toIso8601String() }}">{{ $post->published_at?->diffForHumans() }}</time>
    </header>
    <h2><a href="/p/{{ $post->slug }}">{{ $post->title }}</a></h2>
    <p>{{ \Illuminate\Support\Str::limit($post->excerpt, 180) }}</p>
    <footer>
        @foreach ($post->tags ?? [] as $tag)
            <span class="tag">#{{ $tag }}</span>
        @endforeach
        <x-icon.eye :size="14" /> {{ number_format($post->views) }}
    </footer>
</article>
