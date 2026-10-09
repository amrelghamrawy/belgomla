<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Requests;

use App\Modules\Messaging\Enums\Channel;
use Illuminate\Foundation\Http\FormRequest;

/** POST /api/webhooks/hypersender/{channel}: `{ event, data }` (Hypersender webhook envelope). */
final class HypersenderWebhookRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'event' => ['required', 'string', 'max:100'],
            'data' => ['required', 'array'],
        ];
    }

    public function channel(): Channel
    {
        return Channel::from((string) $this->route('channel'));
    }

    /**
     * The whole webhook body, as Hypersender sent it.
     *
     * @return array<array-key, mixed>
     */
    public function payload(): array
    {
        return $this->all();
    }
}
