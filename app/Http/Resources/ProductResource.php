<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'        => $this->id,
            'sku'       => $this->sku,
            'name'      => $this->name,
            'price'     => (float) $this->price,
            'stock'     => (int) $this->stock,
            'is_active' => (bool) $this->is_active,
            'category'  => $this->whenLoaded('category', function () {
                return [
                    'id'   => $this->category->id,
                    'name' => $this->category->name,
                ];
            }),
        ];
    }
}
