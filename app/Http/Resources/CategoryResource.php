<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'name'           => $this->name,
            'description'    => $this->description,
            // Disponible cuando la consulta usa withCount('products'), p. ej. en index()
            'products_count' => $this->when(! is_null($this->products_count), fn () => (int) $this->products_count),
            'products'       => ProductResource::collection($this->whenLoaded('products')),
        ];
    }
}
