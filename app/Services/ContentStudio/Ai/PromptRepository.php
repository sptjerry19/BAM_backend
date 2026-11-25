<?php

namespace App\Services\ContentStudio\Ai;

use RuntimeException;

/**
 * Builds the planner prompt from versioned files in resources/ai.
 *
 * Only what the task needs is loaded. In particular the skill's
 * channel-context.md (account ids, tool notes, user feedback) and SKILL.md
 * (agent workflow, file handling) are NOT sent to the model.
 */
final class PromptRepository
{
    private const POLICY_HEADER = "\n\n---\n# Chính sách biên tập trang bị (tài liệu của kênh)\n"
        ."Đây là quy tắc về NỘI DUNG. Bỏ qua mọi hướng dẫn về công cụ, tệp, thư mục hay tạo ảnh.\n\n";

    /**
     * @param  list<string>  $slots  slots that will be requested
     * @return array{instructions: string, version: string, schema: array<string, mixed>}
     */
    public function planner(array $slots): array
    {
        $parts = [$this->read('resources/ai/prompts/planner.md')];

        if (in_array('equipment', $slots, true)) {
            $parts[] = self::POLICY_HEADER.$this->read('resources/ai/skills/tiktok-growth-studio/references/equipment-content.md');
        }

        $schemaJson = $this->read('resources/ai/schemas/daily-plan.schema.json');
        $schema = json_decode($schemaJson, true, 512, JSON_THROW_ON_ERROR);

        $instructions = implode('', $parts);

        return [
            'instructions' => $instructions,
            // Changes whenever any text the model actually sees (or the schema) changes.
            'version' => 'planner-'.substr(hash('sha256', $instructions.$schemaJson), 0, 12),
            'schema' => $schema,
        ];
    }

    private function read(string $relative): string
    {
        $path = base_path($relative);
        $text = is_file($path) ? file_get_contents($path) : false;
        if ($text === false) {
            throw new RuntimeException("Prompt file missing: {$relative}");
        }

        return $text;
    }
}
