<?php

namespace App\Livewire;

use App\Models\Post;
use Livewire\Component;

/** A feed page fragment: Flux components and Blaze-compiled post cards (resources/views/components/post). */
class PostFeed extends Component
{
    public int $limit = 30;

    public int $offset = 0;

    public string $tag = '';

    public function more(): void
    {
        $this->limit += 30;
    }

    public function render()
    {
        $posts = Post::query()
            ->with('source')
            ->when($this->tag !== '', fn ($query) => $query->whereJsonContains('tags', $this->tag))
            ->orderByDesc('published_at')
            ->skip($this->offset)
            ->limit($this->limit)
            ->get();

        return view('livewire.post-feed', ['posts' => $posts]);
    }
}
