<?php

namespace Tests\Unit\ContentStudio;

use App\Services\ContentStudio\Ai\PromptRepository;
use Tests\TestCase;

class PromptRepositoryTest extends TestCase
{
    public function test_equipment_policy_is_loaded_only_when_equipment_is_requested(): void
    {
        $repo = new PromptRepository();

        $without = $repo->planner(['tutorial', 'meme']);
        $with = $repo->planner(['equipment', 'tutorial']);

        $this->assertStringNotContainsString('Chính sách biên tập trang bị', $without['instructions']);
        $this->assertStringContainsString('Chính sách biên tập trang bị', $with['instructions']);
        $this->assertStringContainsString('Kiểm chứng thông tin sản phẩm', $with['instructions']);
        $this->assertNotSame($without['version'], $with['version'], 'a different prompt must have a different version');
    }

    public function test_the_version_is_stable_and_well_formed(): void
    {
        $repo = new PromptRepository();

        $this->assertSame($repo->planner(['news'])['version'], $repo->planner(['news'])['version']);
        $this->assertMatchesRegularExpression('/^planner-[0-9a-f]{12}$/', $repo->planner(['news'])['version']);
    }

    public function test_operational_skill_files_and_account_ids_are_never_sent_to_the_model(): void
    {
        $text = (new PromptRepository())->planner(['news', 'equipment', 'tutorial', 'meme'])['instructions'];

        foreach (['Metricool', 'Google Drive','FFmpeg', 'computer-use', 'Runway', 'vidIQ'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $text, "'{$forbidden}' comes from the agent-oriented skill files");
        }
    }

    public function test_the_prompt_states_the_non_negotiable_rules(): void
    {
        $text = (new PromptRepository())->planner(['news'])['instructions'];

        foreach (['dữ liệu không đáng tin', 'source_ids', 'skipped_slots', 'carousel', 'Thà bỏ qua một slot'] as $needle) {
            $this->assertStringContainsString($needle, $text);
        }
    }

    public function test_the_schema_is_valid_for_strict_structured_outputs(): void
    {
        $schema = (new PromptRepository())->planner(['news'])['schema'];

        $this->assertStrictObjects($schema, '$');
    }

    /** Strict mode needs additionalProperties=false and every property listed in `required`, at every object level. */
    private function assertStrictObjects(array $node, string $path): void
    {
        if (($node['type'] ?? null) === 'object') {
            $this->assertFalse($node['additionalProperties'] ?? true, "{$path}: additionalProperties must be false");
            $this->assertEqualsCanonicalizing(array_keys($node['properties']), $node['required'], "{$path}: every property must be required");
        }
        foreach (['properties', 'items'] as $key) {
            if (! isset($node[$key])) {
                continue;
            }
            $children = $key === 'properties' ? $node[$key] : ['items' => $node[$key]];
            foreach ($children as $name => $child) {
                if (is_array($child)) {
                    $this->assertStrictObjects($child, "{$path}.{$name}");
                }
            }
        }
    }
}
