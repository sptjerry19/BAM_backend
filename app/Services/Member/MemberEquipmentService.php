<?php

namespace App\Services\Member;

use App\Models\Member;
use App\Models\MemberEquipment;
use App\Services\Social\MediaService;
use App\Services\Storage\FileUploadService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MemberEquipmentService
{
    public function __construct(private readonly FileUploadService $uploads) {}

    /**
     * @return Collection<int, MemberEquipment>
     */
    public function listForMember(string $memberId): Collection|bool
    {
        // Existing management endpoint is owner-only. Visitors use the filtered profile resource.
        abort_unless(auth('api')->user()?->member_id === (int) $memberId, 403);
        try {
            Member::findOrFail($memberId);

            $items = MemberEquipment::query()
                ->where('member_id', $memberId)
                ->orderByDesc('created_at')
                ->get();
            foreach ($items as $item) {
                if ($item->image) {
                    $item->image = app(MediaService::class)->equipmentResource($item->id, $item->image);
                }
            }

            return $items;
        } catch (\Exception $e) {
            Log::error($e->getMessage());

            return false;
        }
    }

    public function create(string $memberId, array $data): MemberEquipment|bool
    {
        abort_unless(auth('api')->user()?->member_id === (int) $memberId, 403);
        $uploaded = null;
        try {
            Member::findOrFail($memberId);

            $rawImage = $data['image'] ?? null;
            if (is_string($rawImage) && (str_starts_with($rawImage, 'data:image/') || $this->looksLikeBase64($rawImage))) {
                $uploaded = $this->uploads->uploadBase64($rawImage, 'equipment', 'social_private');
                $image = null;
            } else {
                $image = $this->resolveImageUrl($rawImage);
            }

            $data = [
                'member_id' => $memberId,
                'name' => $data['name'],
                'brand' => $data['brand'] ?? null,
                'category' => $data['category'],
                'price' => $data['price'] ?? null,
                'notes' => $data['notes'] ?? null,
                'image' => $image,
            ];

            $equipment = DB::transaction(function () use ($data, $uploaded) {
                $item = MemberEquipment::create($data);
                if ($uploaded) {
                    $id = (string) Str::uuid();
                    DB::table('media_assets')->insert(['id' => $id, 'user_id' => auth('api')->id(), 'equipment_id' => $item->id,
                        'purpose' => 'equipment', 'disk' => $uploaded['disk'], 'path' => $uploaded['path'], 'mime' => $uploaded['mime'],
                        'size' => $uploaded['size'], 'created_at' => now(), 'updated_at' => now()]);
                    $item->update(['image' => 'private:'.$id]);
                }

                return $item;
            });
            if ($equipment->image) {
                $equipment->image = app(MediaService::class)->equipmentResource($equipment->id, $equipment->image);
            }

            return $equipment;
        } catch (\Exception $e) {
            if ($uploaded) {
                Storage::disk($uploaded['disk'])->delete($uploaded['path']);
            }
            Log::error($e->getMessage());

            return false;
        }
    }

    public function delete(string $id): void
    {
        $equipment = MemberEquipment::findOrFail($id);

        abort_unless(auth('api')->user()?->member_id === $equipment->member_id, 403);

        $media = DB::table('media_assets')->where('equipment_id', $equipment->id)->first();
        if ($media) {
            Storage::disk($media->disk)->delete($media->path);
        } elseif ($equipment->image) {
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
