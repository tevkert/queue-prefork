<div class="feed">
    <div class="flex items-center justify-between">
        <flux:heading size="lg">Latest posts</flux:heading>
        <flux:input wire:model.live.debounce.300ms="tag" placeholder="Filter by tag" icon="magnifying-glass" />
    </div>

    <flux:separator class="my-4" />

    @forelse ($posts as $post)
        <x-post.card :post="$post" wire:key="post-{{ $post->id }}" />
    @empty
        <flux:text>No posts yet.</flux:text>
    @endforelse

    <flux:button wire:click="more" variant="primary" icon="arrow-down">More</flux:button>
</div>
