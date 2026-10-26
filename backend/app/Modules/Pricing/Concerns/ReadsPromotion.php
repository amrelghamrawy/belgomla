<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Concerns;

use App\Modules\Catalog\Concerns\ReadsProductFilter;
use App\Modules\Catalog\Data\ProductFilterData;
use App\Modules\Pricing\Enums\PromotionStatus;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;

/**
 * BR-PR15 (V2 Task 12, D90): the promotion fields of the create and edit requests and their typed accessors. Times
 * without an offset are Cairo wall time (D60, the dashboard's date-time pickers) and come out UTC; a field that was not
 * sent reads as null. The target is `filter` (the dashboard product filter, by category id) or `sku_ids`, never both.
 */
trait ReadsPromotion
{
    use ReadsProductFilter;

    public const int MAX_SKUS = 1000;

    /**
     * Every rule as on create; the edit request drops `required`.
     *
     * @return array<string, list<mixed>>
     */
    protected function promotionRules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:100'],
            'discount_percent' => ['required', 'decimal:0,2', 'gt:0', 'lt:90'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date'],
            'filter' => ['required_without:sku_ids', 'nullable', 'array', 'prohibits:sku_ids'],
            ...$this->filterRules(),
            'sku_ids' => ['required_without:filter', 'nullable', 'array', 'min:1', 'max:'.self::MAX_SKUS],
            'sku_ids.*' => ['integer', 'distinct'],
            'status' => ['sometimes', Rule::in([PromotionStatus::Draft->value, PromotionStatus::Scheduled->value])],
        ];
    }

    public function name(): ?string
    {
        return $this->has('name') ? mb_trim($this->string('name')->value()) : null;
    }

    public function discountPercent(): ?string
    {
        return $this->filled('discount_percent') ? Money::normalize($this->string('discount_percent')->value()) : null;
    }

    public function startsAt(): ?CarbonImmutable
    {
        return $this->moment('starts_at');
    }

    public function endsAt(): ?CarbonImmutable
    {
        return $this->moment('ends_at');
    }

    public function filter(): ?ProductFilterData
    {
        return is_array($this->input('filter')) ? new ProductFilterData(...$this->productFilterInput()) : null;
    }

    /**
     * @return list<int>|null
     */
    public function skuIds(): ?array
    {
        if (! is_array($this->input('sku_ids'))) {
            return null;
        }

        return array_values(array_map(intval(...), array_filter($this->input('sku_ids'), is_numeric(...))));
    }

    public function status(): ?PromotionStatus
    {
        return $this->enum('status', PromotionStatus::class);
    }

    /** The dashboard user saving the promotion (promotions.created_by). */
    public function actorId(): ?int
    {
        $id = $this->user('tenant_user')?->getAuthIdentifier();

        return is_int($id) ? $id : null;
    }

    protected function filterPrefix(): string
    {
        return 'filter.';
    }

    private function moment(string $key): ?CarbonImmutable
    {
        return $this->filled($key)
            ? CarbonImmutable::parse($this->string($key)->value(), config()->string('app.business_timezone'))->utc()
            : null;
    }
}
