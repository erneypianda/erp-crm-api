<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'       => $this->id,
            'subtotal' => (float) $this->subtotal,
            'tax'      => (float) $this->tax,
            'total'    => (float) $this->total,
            'status'   => $this->status,
            'customer' => new CustomerResource($this->whenLoaded('customer')),
            'user'     => $this->whenLoaded('user', function () {
                return [
                    'id'   => $this->user->id,
                    'name' => $this->user->name,
                ];
            }),
            'items'    => SaleItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
