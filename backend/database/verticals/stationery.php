<?php

declare(strict_types=1);

/*
| BR-P09 stationery template (D33): brand, color, size as filterable selects.
*/

return [
    [
        'key' => 'brand',
        'label' => 'الماركة',
        'type' => 'select',
        'is_filterable' => true,
        'filter_order' => 1,
        'sort_order' => 1,
        'options' => [],
    ],
    [
        'key' => 'color',
        'label' => 'اللون',
        'type' => 'select',
        'is_filterable' => true,
        'filter_order' => 2,
        'sort_order' => 2,
        'options' => ['أزرق', 'أسود', 'أحمر', 'أخضر', 'أبيض', 'ألوان متعددة'],
    ],
    [
        'key' => 'size',
        'label' => 'المقاس',
        'type' => 'select',
        'is_filterable' => true,
        'filter_order' => 3,
        'sort_order' => 3,
        'options' => [],
    ],
];
