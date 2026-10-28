<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * One structured completion returning a title, a summary, tags and a sensitivity flag for a post. The provider (OpenRouter) points at the HTTP stub.
 */
class PostEnricher implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'INSTR'
You summarise posts for a reading list. Given a post, return JSON with:
1. `title`: a clear headline, 40-80 characters.
2. `description`: a 140-180 character summary of the post.
3. `tags`: 3-7 lowercase, singular topical tags, broad to narrow, no duplicates.
4. `is_sensitive`: whether the post's media is graphic or explicit and should be blurred.
INSTR;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->required(),
            'description' => $schema->string()->required(),
            'tags' => $schema->array()->items($schema->string())->required(),
            'is_sensitive' => $schema->boolean()->required(),
        ];
    }
}
