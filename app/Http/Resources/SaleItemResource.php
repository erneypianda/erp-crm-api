<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'product_id'  => $this->product_id,
            'quantity'    => (int) $this->quantity,
            'unit_price'  => (float) $this->unit_price,
            'total_price' => (float) $this->total_price,
            'product'     => new ProductResource($this->whenLoaded('product')),
        ];
    }
}
