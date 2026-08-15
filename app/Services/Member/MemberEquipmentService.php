<?php

namespace App\Services\Member;

use App\Models\Member;
use App\Models\MemberEquipment;
use App\Services\Storage\FileUploadService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MemberEquipmentService
{
    public function __construct(private readonly FileUploadService $uploads)
    {
    }

    /**
     * @return Collection<int, MemberEquipment>
     */
    public function listForMember(string $memberId): Collection|bool
    {
        try {
            Member::findOrFail($memberId);

            return MemberEquipment::query()
                ->where('member_id', $memberId)
                ->orderByDesc('created_at')
                ->get();
        } catch (\Exception $e) {
            Log::error($e->getMessage());

            return false;
        }
    }

    public function create(string $memberId, array $data): MemberEquipment|bool
    {
        try {
            Member::findOrFail($memberId);

            $image = $this->resolveImageUrl($data['image'] ?? null);

            $data = [
                'member_id' => $memberId,
                'name' => $data['name'],
                'brand' => $data['brand'] ?? null,
                'category' => $data['category'],
                'price' => $data['price'] ?? null,
                'notes' => $data['notes'] ?? null,
                'image' => $image,
            ];

            return MemberEquipment::create($data);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return false;
        }
    }

    public function delete(string $id): void
    {
        $equipment = MemberEquipment::findOrFail($id);

        if ($equipment->image) {
            $this->uploads->delete($equipment->image);
        }

        $equipment->delete();
    }

    private function resolveImageUrl(mixed $image): ?string
    {
        if (! is_string($image) || $image === '') {
            return null;
        }

        // Already a remote/public URL — keep as-is.
        if (str_starts_with($image, 'http://') || str_starts_with($image, 'https://')) {
            return $image;
        }

        // data:image/...;base64,... or raw base64
        if (str_starts_with($image, 'data:image/') || $this->looksLikeBase64($image)) {
            return $this->uploads->uploadBase64($image, 'equipments')['url'];
        }

        return $image;
    }

    private function looksLikeBase64(string $value): bool
    {
        // Avoid treating short notes / paths as base64.
        if (strlen($value) < 64) {
            return false;
        }

        return (bool) preg_match('/^[A-Za-z0-9+\/\r\n]+={0,2}$/', $value);
    }
}
