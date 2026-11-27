<?php

namespace App\Services\Catalog;

use App\Exceptions\ApiException;
use App\Services\Media\ImageStorage;
use App\Services\Media\LocalImageStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Las fotos de un producto. La de posición 0 es la principal. */
class ProductImages
{
    public const MAX_PER_PRODUCT = 8;

    public function __construct(
        private readonly ImageStorage $storage,
        private readonly LocalImageStorage $local,
    ) {}

    /** @return Collection<int, object> */
    public function of(string $productId): Collection
    {
        return DB::table('product_images')
            ->where('product_id', $productId)
            ->orderBy('position')
            ->orderBy('created_at')
            ->get();
    }

    public function add(string $productId, UploadedFile $file): void
    {
        $images = $this->of($productId);

        if ($images->count() >= self::MAX_PER_PRODUCT) {
            throw ApiException::invalid('Un producto admite hasta '.self::MAX_PER_PRODUCT.' fotos.');
        }

        $stored = $this->storage->store($file, "productos/{$productId}");

        try {
            DB::table('product_images')->insert([
                'id_product_image' => (string) Str::uuid7(),
                'product_id' => $productId,
                'public_id' => $stored['public_id'],
                'url' => $stored['url'],
                'position' => $images->isEmpty() ? 0 : $images->max('position') + 1,
            ]);
        } catch (Throwable $e) {
            $this->storage->delete($stored['public_id']);

            throw $e;
        }
    }

    public function makeMain(string $productId, string $imageId): void
    {
        DB::transaction(function () use ($productId, $imageId) {
            $ids = $this->of($productId)->pluck('id_product_image');
            abort_unless($ids->contains($imageId), 404);

            $ids->reject(fn (string $id) => $id === $imageId)->prepend($imageId)->values()
                ->each(fn (string $id, int $position) => DB::table('product_images')
                    ->where('id_product_image', $id)
                    ->update(['position' => $position]));
        });
    }

    public function remove(string $productId, string $imageId): void
    {
        $image = DB::table('product_images')
            ->where('product_id', $productId)
            ->where('id_product_image', $imageId)
            ->first() ?? abort(404);

        DB::table('product_images')->where('id_product_image', $imageId)->delete();

        // Se borra donde se guardó, aunque hoy el proveedor sea otro. Si falla,
        // queda un archivo suelto: no es motivo para mostrar un error.
        try {
            if ($this->local->owns($image->public_id)) {
                $this->local->delete($image->public_id);
            } elseif ($this->storage->owns($image->public_id)) {
                $this->storage->delete($image->public_id);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
